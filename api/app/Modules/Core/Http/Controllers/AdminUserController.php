<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Mail\UserInvitation;
use App\Models\User;
use App\Modules\Core\Services\SupabaseAdminClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class AdminUserController extends Controller
{
    public function index(): JsonResponse
    {
        $projectCounts = DB::table('project_members')
            ->select('user_id', DB::raw('count(*) as count'))
            ->groupBy('user_id')
            ->pluck('count', 'user_id');

        $users = User::query()
            ->orderBy('email')
            ->get(['id', 'email', 'name', 'role', 'created_at'])
            ->map(function (User $u) use ($projectCounts) {
                return [
                    'id' => $u->id,
                    'email' => $u->email,
                    'name' => $u->name,
                    'role' => $u->role,
                    'created_at' => $u->created_at?->toIso8601String(),
                    'projects_count' => (int) ($projectCounts[$u->id] ?? 0),
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

        $redirectTo = rtrim((string) config('app.url'), '/').'/account/setup';

        try {
            $invite = $supabase->generateInviteLink($email, $redirectTo, [
                'name' => $data['name'],
            ]);
        } catch (RuntimeException $e) {
            Log::error('Supabase invite failed', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Impossible de créer l\'utilisateur côté Supabase. '.$e->getMessage(),
            ], 502);
        }

        $user = User::create([
            'id' => $invite['user_id'],
            'email' => $email,
            'name' => $data['name'],
            'role' => $data['role'],
        ]);

        try {
            Mail::to($email)->send(new UserInvitation(
                name: $data['name'],
                actionLink: $invite['action_link'],
                inviterName: $request->attributes->get('user')?->name,
            ));
        } catch (\Throwable $e) {
            Log::error('Invitation email failed', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
            // On ne rollback pas — l'utilisateur existe, l'admin peut renvoyer l'invitation.
        }

        return response()->json([
            'id' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'role' => $user->role,
            'created_at' => $user->created_at?->toIso8601String(),
            'projects_count' => 0,
        ], 201);
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

        $user->update($data);

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

        return response()->json(null, 204);
    }
}
