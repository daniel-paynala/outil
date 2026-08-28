<?php

namespace App\Modules\Calls\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Calls\Models\CallLog;
use App\Modules\Calls\Models\VoipDevice;
use App\Modules\Calls\Services\ApnsVoipSender;
use App\Modules\Messagerie\Services\PushSender;
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
    public function __construct(
        private readonly ApnsVoipSender $apns,
        private readonly PushSender $push,
    ) {}

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

        // Android sonne par notification, pas par PushKit — voir `ringAndroid`.
        if ($appareils->contains(fn (VoipDevice $a) => $a->platform !== 'ios')) {
            $this->ringAndroid($data, $moi);
            $atteints++;
        }

        foreach ($appareils as $appareil) {
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
     * Fait sonner un Android par notification.
     *
     * ## Pourquoi pas le même chemin qu'iOS
     *
     * Android n'a pas de PushKit : rien ne permet de réveiller l'application
     * pour qu'elle affiche l'écran d'appel du système. Le vrai équivalent
     * demanderait `firebase_messaging` aux côtés de OneSignal, donc deux SDK
     * qui se disputent le même jeton FCM. Ce n'est pas ce qu'on fait ici.
     *
     * Ce qu'on fait : une notification de la plus haute priorité, avec la
     * sonnerie et la vibration. Ce n'est pas l'écran d'appel du système, mais
     * cela remplace le silence — et le silence était le vrai problème.
     *
     * ## Les réglages, et pourquoi chacun
     *
     * `priority: 10` et `ttl: 45` : un appel n'a de sens que pendant qu'il
     * sonne. Passé la sonnerie, une notification livrée en retard annonce un
     * appel qui n'existe plus, ce qui est pire que rien.
     *
     * `isIos: false` : le destinataire peut avoir un iPhone **et** un Android.
     * Sans ce filtre, l'iPhone recevrait à la fois le push VoIP et cette
     * notification, et sonnerait deux fois pour un seul appel.
     *
     * @param  array<string, mixed>  $data
     */
    private function ringAndroid(array $data, User $appelant): void
    {
        $nom = $appelant->name ?: explode('@', $appelant->email)[0];

        $this->push->send(
            userIds: [$data['to_user_id']],
            title: 'Appel entrant',
            body: "{$nom} vous appelle",
            data: [
                'type' => 'call',
                'call_id' => $data['call_id'],
                'caller_id' => $appelant->id,
                'caller_name' => $nom,
                'caller_email' => $appelant->email,
            ],
            options: [
                'priority' => 10,
                'ttl' => 45,
                'isIos' => false,
                'android_sound' => 'default',
                // Le badge n'a rien à voir avec un appel : l'incrémenter
                // laisserait une pastille qu'aucun écran ne vient effacer.
                'ios_badge_type' => 'None',
            ],
        );
    }

    /**
     * Historique des appels, ceux passés comme ceux reçus.
     *
     * Une seule ligne par appel, écrite par l'appelant : la recherche porte
     * donc sur les deux colonnes pour que chacun retrouve la conversation de
     * son côté.
     */
    public function history(Request $request): JsonResponse
    {
        $moi = $this->userId($request);

        $appels = CallLog::query()
            ->where('caller_id', $moi)
            ->orWhere('callee_id', $moi)
            ->with([
                'caller:id,email,name,avatar_path',
                'callee:id,email,name,avatar_path',
            ])
            ->orderByDesc('created_at')
            // Cinquante : un historique d'appels se consulte pour retrouver
            // quelque chose de récent. Au-delà, la recherche par personne sert
            // mieux qu'un défilement.
            ->limit(50)
            ->get();

        return response()->json($appels);
    }

    /**
     * Consigne un appel terminé.
     *
     * Appelé par **l'appelant seul**, quelle que soit l'issue : un appel refusé
     * ou sans réponse doit figurer à l'historique des deux côtés — c'est même
     * la ligne la plus utile, celle qui rappelle qu'on doit rappeler.
     */
    public function log(Request $request): JsonResponse
    {
        $data = $request->validate([
            'callee_id' => ['required', 'uuid', 'exists:users,id'],
            'connected_at' => ['nullable', 'date'],
            'duration' => ['required', 'integer', 'min:0'],
            'end_reason' => ['required', 'string', 'max:20'],
            'route' => ['nullable', 'string', 'max:10'],
        ]);

        $appel = CallLog::create([
            'caller_id' => $this->userId($request),
            ...$data,
        ]);

        return response()->json($appel, 201);
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
