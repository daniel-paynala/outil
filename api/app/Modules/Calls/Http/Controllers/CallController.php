<?php

namespace App\Modules\Calls\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Calls\Models\VoipDevice;
use App\Modules\Calls\Services\ApnsVoipSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ce que le serveur fait pour les appels — et rien de plus.
 *
 * La voix ne passe pas par ici : elle va d'un téléphone à l'autre en direct.
 * La signalisation non plus : elle emprunte le socket Supabase. Le serveur ne
 * sert qu'à **faire sonner un appareil que l'application ne peut pas
 * atteindre** — écran verrouillé, app fermée. C'est la seule chose qu'un
 * téléphone endormi ne sait pas faire tout seul.
 */
class CallController extends Controller
{
    public function __construct(private readonly ApnsVoipSender $apns) {}

    /**
     * Enregistre le jeton VoIP de cet appareil.
     *
     * Appelé à chaque démarrage : le jeton change à la réinstallation, à la
     * restauration d'une sauvegarde et parfois à une mise à jour d'iOS. Ne
     * l'enregistrer qu'une fois laisserait des appareils injoignables sans que
     * personne ne comprenne pourquoi.
     */
    public function registerDevice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'platform' => ['required', 'in:ios,android'],
        ]);

        VoipDevice::updateOrCreate(
            ['token' => $data['token']],
            [
                'user_id' => $this->userId($request),
                'platform' => $data['platform'],
                'last_used_at' => now(),
            ],
        );

        return response()->json(null, 204);
    }

    /**
     * Fait sonner les appareils du destinataire.
     *
     * Rend le nombre d'appareils atteints : zéro signifie que personne ne
     * sonnera, et l'appelant peut le dire plutôt que de laisser tourner un
     * appel qui n'aboutira jamais.
     */
    public function ring(Request $request): JsonResponse
    {
        $data = $request->validate([
            'call_id' => ['required', 'uuid'],
            'to_user_id' => ['required', 'uuid', 'exists:users,id'],
        ]);

        $moi = User::find($this->userId($request));
        if ($moi === null) {
            abort(401);
        }

        // On ne s'appelle pas soi-même : cela ferait sonner l'appareil qui
        // compose, ce qui n'a aucun sens et déroute.
        abort_if($data['to_user_id'] === $moi->id, 422, 'Cannot ring yourself');

        $appareils = VoipDevice::where('user_id', $data['to_user_id'])->get();
        $atteints = 0;

        foreach ($appareils as $appareil) {
            // Seul iOS a besoin d'un push VoIP : sur Android, un message de
            // haute priorité suffit, et cette voie n'est pas encore branchée.
            if ($appareil->platform !== 'ios') {
                continue;
            }

            $ok = $this->apns->ring($appareil->token, [
                'call_id' => $data['call_id'],
                'caller_id' => $moi->id,
                'caller_name' => $moi->name ?: explode('@', $moi->email)[0],
                'caller_email' => $moi->email,
            ]);

            if ($ok) {
                $atteints++;
                $appareil->update(['last_used_at' => now()]);
            }
        }

        return response()->json([
            'devices' => $appareils->count(),
            'reached' => $atteints,
        ]);
    }

    /**
     * Identifiants temporaires pour le serveur de relais.
     *
     * ## Pourquoi ne pas mettre un mot de passe dans l'application
     *
     * Il serait extractible de n'importe quel APK, et le relais deviendrait un
     * service gratuit pour qui l'a trouvé — la bande passante d'un TURN se
     * facture, pas le logiciel.
     *
     * coturn accepte à la place un mécanisme où le mot de passe est **calculé**
     * : le nom d'utilisateur porte une date d'expiration, le mot de passe en
     * est la signature par un secret que seul le serveur connaît. Le client
     * reçoit un couple valable quelques heures et ne peut pas en fabriquer
     * d'autre.
     *
     * Rendu vide plutôt qu'en erreur quand aucun relais n'est configuré : les
     * appels fonctionnent sans, simplement pas partout, et l'app le signale
     * déjà dans le monitoring.
     */
    public function turnCredentials(Request $request): JsonResponse
    {
        $secret = config('calls.turn_secret');
        $hote = config('calls.turn_host');

        if (empty($secret) || empty($hote)) {
            return response()->json(['servers' => []]);
        }

        // Douze heures : bien plus qu'un appel, assez peu pour qu'un couple
        // intercepté ne serve pas longtemps. L'app en redemande à chaque appel,
        // le renouvellement est donc gratuit.
        $expiration = now()->addHours(12)->timestamp;
        $utilisateur = $expiration.':'.$this->userId($request);

        return response()->json([
            'servers' => [
                [
                    'urls' => [
                        "turn:{$hote}:3478?transport=udp",
                        // Certains réseaux d'entreprise bloquent l'UDP en
                        // totalité. Sans repli TCP, l'appel y échoue malgré le
                        // relais.
                        "turn:{$hote}:3478?transport=tcp",
                        "turns:{$hote}:5349?transport=tcp",
                    ],
                    'username' => $utilisateur,
                    'credential' => base64_encode(
                        hash_hmac('sha1', $utilisateur, $secret, true),
                    ),
                ],
            ],
            'expires_at' => $expiration,
        ]);
    }

    private function userId(Request $request): string
    {
        return $request->attributes->get('supabase_user_id')
            ?? abort(401, 'Missing user id');
    }
}
