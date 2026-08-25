<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Mail\PasswordReset;
use App\Models\InvitationToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class PasswordResetController extends Controller
{
    /**
     * POST /api/auth/forgot-password
     * Public — déclenche un email de reset.
     *
     * Pour empêcher l'enumeration des emails, on retourne toujours 200 même si
     * l'email n'existe pas.
     */
    public function request(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
        ]);

        $email = strtolower(trim($data['email']));
        $user = User::where('email', $email)->first();

        if ($user) {
            // Invalide les anciens tokens non consommés
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
                Mail::to($user->email)->send(new PasswordReset(
                    name: $user->name ?? $user->email,
                    actionLink: $actionLink,
                ));
            } catch (\Throwable $e) {
                Log::error('Password reset email failed', [
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Même réponse dans tous les cas pour ne pas révéler si l'email existe
        return response()->json([
            'message' => 'Si un compte existe avec cet email, un lien a été envoyé.',
        ]);
    }
}
