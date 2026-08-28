<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\InvitationToken;
use App\Modules\Activity\Services\ActivityLogger;
use App\Modules\Core\Services\SupabaseAdminClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class InvitationController extends Controller
{
    /**
     * GET /api/invite/{token}
     * Public — used by the invitation page to display user info before
     * showing the password form. Does NOT consume the token (a scanner can
     * pre-fetch this safely).
     */
    public function show(string $token): JsonResponse
    {
        $invitation = InvitationToken::with('user:id,email,name,avatar_path')
            ->where('token', $token)
            ->first();

        if (! $invitation) {
            return response()->json(['error' => 'Lien d\'invitation introuvable.'], 404);
        }

        if ($invitation->used_at !== null) {
            return response()->json(['error' => 'Ce lien a déjà été utilisé.'], 410);
        }

        if ($invitation->expires_at->isPast()) {
            return response()->json(['error' => 'Ce lien a expiré.'], 410);
        }

        return response()->json([
            'email' => $invitation->user->email,
            'name' => $invitation->user->name,
        ]);
    }

    /**
     * POST /api/invite/{token}/activate
     * Public — sets the user's password via Supabase Admin API and marks the
     * token as consumed. Returns success so the frontend can sign-in.
     */
    public function activate(Request $request, string $token, SupabaseAdminClient $supabase, ActivityLogger $activity): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'string', 'min:12', 'max:128'],
        ]);

        $invitation = InvitationToken::with('user')
            ->where('token', $token)
            ->first();

        if (! $invitation) {
            return response()->json(['error' => 'Lien d\'invitation introuvable.'], 404);
        }

        if ($invitation->used_at !== null) {
            return response()->json(['error' => 'Ce lien a déjà été utilisé.'], 410);
        }

        if ($invitation->expires_at->isPast()) {
            return response()->json(['error' => 'Ce lien a expiré.'], 410);
        }

        try {
            $supabase->setUserPassword($invitation->user_id, $request->input('password'));
        } catch (RuntimeException $e) {
            Log::error('Activation: setUserPassword failed', [
                'user_id' => $invitation->user_id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Impossible d\'enregistrer le mot de passe. '.$e->getMessage(),
            ], 502);
        }

        $invitation->update(['used_at' => now()]);

        $activity->logGlobal(
            $invitation->user_id,
            'user.account_activated',
            $invitation->user,
            $invitation->user->email,
        );

        return response()->json([
            'message' => 'Compte activé.',
            'email' => $invitation->user->email,
        ]);
    }
}
