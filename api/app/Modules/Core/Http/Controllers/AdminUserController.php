<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Mail\UserInvitation;
use App\Models\InvitationToken;
use App\Models\User;
use App\Modules\Activity\Services\ActivityLogger;
use App\Modules\Core\Services\SupabaseAdminClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;

class AdminUserController extends Controller
{
    public function __construct(private ActivityLogger $activity) {}

    public function index(SupabaseAdminClient $supabase): JsonResponse
    {
        $projectCounts = DB::table('project_members')
            ->select('user_id', DB::raw('count(*) as count'))
            ->groupBy('user_id')
            ->pluck('count', 'user_id');

        // Statut Supabase pour savoir si l'invitation est encore en attente
        $supabaseByEmail = [];
        try {
            $supabaseByEmail = $supabase->listUsersByEmail();
        } catch (\Throwable $e) {
            Log::warning('Could not fetch Supabase users for invitation status', [
                'error' => $e->getMessage(),
            ]);
        }

        $users = User::query()
            ->orderBy('email')
            ->get(['id', 'email', 'name', 'role', 'avatar_path', 'created_at'])
            ->map(function (User $u) use ($projectCounts, $supabaseByEmail) {
                $sb = $supabaseByEmail[strtolower($u->email)] ?? null;
                $invitationPending = $sb === null || empty($sb['email_confirmed_at']);

                return [
                    'id' => $u->id,
                    'email' => $u->email,
                    'name' => $u->name,
                    'role' => $u->role,
                    'avatar_url' => $u->avatar_url,
                    'created_at' => $u->created_at?->toIso8601String(),
                    'projects_count' => (int) ($projectCounts[$u->id] ?? 0),
                    'invitation_pending' => $invitationPending,
                    'last_sign_in_at' => $sb['last_sign_in_at'] ?? null,
                ];
            });

        return response()->json($users);
    }

    public function store(Request $request, SupabaseAdminClient $supabase): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'name' => ['required', 'string', 'max:120'],
            'role' => ['required', 'in:member,admin'],
        ]);

        $email = strtolower(trim($data['email']));

        if (User::where('email', $email)->exists()) {
            return response()->json([
                'error' => 'Un utilisateur avec cet email existe déjà.',
            ], 422);
        }

        // 1) Crée l'utilisateur côté Supabase (sans password, email confirmé)
        try {
            $supabaseUser = $supabase->createUser($email, ['name' => $data['name']]);
        } catch (RuntimeException $e) {
            Log::error('Supabase create user failed', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Impossible de créer l\'utilisateur côté Supabase. '.$e->getMessage(),
            ], 502);
        }

        // 2) Crée le row local avec le même UUID
        $user = User::create([
            'id' => $supabaseUser['id'],
            'email' => $email,
            'name' => $data['name'],
            'role' => $data['role'],
        ]);

        // 3) Génère un token d'invitation custom + envoie notre email
        $this->createTokenAndSendEmail($user, $request->attributes->get('user')?->name);

        $this->activity->logGlobal(
            $request->attributes->get('supabase_user_id'),
            'admin.user.invited',
            $user,
            $user->email,
            ['role' => $user->role],
        );

        return response()->json([
            'id' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'role' => $user->role,
            'created_at' => $user->created_at?->toIso8601String(),
            'projects_count' => 0,
            'invitation_pending' => true,
        ], 201);
    }

    /**
     * Génère un nouveau token d'invitation et envoie l'email.
     * Invalide les tokens en cours pour cet user.
     */
    private function createTokenAndSendEmail(User $user, ?string $inviterName): void
    {
        InvitationToken::where('user_id', $user->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $token = InvitationToken::create([
            'user_id' => $user->id,
            'token' => (string) Str::uuid(),
            'expires_at' => now()->addHours(48),
        ]);

        $actionLink = rtrim((string) config('app.url'), '/').'/invite/'.$token->token;

        try {
            Mail::to($user->email)->send(new UserInvitation(
                name: $user->name ?? $user->email,
                actionLink: $actionLink,
                inviterName: $inviterName,
            ));
        } catch (\Throwable $e) {
            Log::error('Invitation email failed', [
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function resendInvite(Request $request, User $user): JsonResponse
    {
        $this->createTokenAndSendEmail($user, $request->attributes->get('user')?->name);

        $this->activity->logGlobal(
            $request->attributes->get('supabase_user_id'),
            'admin.user.invitation_resent',
            $user,
            $user->email,
        );

        return response()->json([
            'message' => 'Invitation renvoyée.',
            'email' => $user->email,
        ]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'role' => ['sometimes', 'in:member,admin'],
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $currentUserId = $request->attributes->get('supabase_user_id');
        if (
            isset($data['role'])
            && $data['role'] !== 'admin'
            && $user->id === $currentUserId
        ) {
            return response()->json([
                'error' => 'Tu ne peux pas te retirer le rôle admin toi-même.',
            ], 422);
        }

        $changes = collect($data)->filter(fn ($v, $k) => $user->getOriginal($k) !== $v)->all();

        $user->update($data);

        if (! empty($changes)) {
            $this->activity->logGlobal(
                $currentUserId,
                'admin.user.updated',
                $user,
                $user->email,
                ['changes' => $changes],
            );
        }

        return response()->json($user->only(['id', 'email', 'name', 'role']));
    }

    public function destroy(Request $request, User $user, SupabaseAdminClient $supabase): JsonResponse
    {
        $currentUserId = $request->attributes->get('supabase_user_id');
        if ($user->id === $currentUserId) {
            return response()->json([
                'error' => 'Tu ne peux pas te supprimer toi-même.',
            ], 422);
        }

        $deletedEmail = $user->email;

        try {
            $supabase->deleteUser($user->id);
        } catch (RuntimeException $e) {
            Log::error('Supabase delete user failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Impossible de supprimer l\'utilisateur côté Supabase. '.$e->getMessage(),
            ], 502);
        }

        $user->delete();

        $this->activity->logGlobal(
            $currentUserId,
            'admin.user.deleted',
            null,
            $deletedEmail,
        );

        return response()->json(null, 204);
    }
}
