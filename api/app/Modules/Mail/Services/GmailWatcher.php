<?php

namespace App\Modules\Mail\Services;

use App\Modules\Mail\Models\GoogleAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Surveillance d'une boîte Gmail et lecture de ce qui y arrive.
 *
 * ## Le mécanisme, et pourquoi il est fragile
 *
 * Gmail ne pousse pas le contenu d'un message. `users.watch()` demande à Google
 * de publier un avis sur un sujet Pub/Sub à chaque changement de la boîte ; cet
 * avis ne contient qu'un `historyId`, c'est-à-dire un numéro de version. C'est
 * en demandant l'historique entre le dernier numéro connu et celui-ci qu'on
 * apprend ce qui s'est passé.
 *
 * Deux conséquences dont dépend tout le reste :
 *
 *  1. **La surveillance expire au bout de sept jours.** Non renouvelée, elle
 *     s'éteint sans erreur, sans avis, sans trace : les notifications cessent
 *     simplement d'arriver. C'est exactement la panne muette qu'Arche a déjà
 *     connue avec sa file d'attente, et elle se diagnostique aussi mal.
 *     D'où le renouvellement quotidien et `watch_expires_at` en base.
 *
 *  2. **L'historique est borné.** Google ne garde qu'une fenêtre glissante :
 *     un `historyId` trop ancien est refusé (404). On reprend alors le numéro
 *     courant sans chercher à rattraper — quelques notifications manquées valent
 *     mieux qu'une surveillance qui ne redémarre jamais.
 */
class GmailWatcher
{
    private const BASE = 'https://gmail.googleapis.com/gmail/v1/users/me';

    public function __construct(private readonly GoogleOAuth $oauth) {}

    /**
     * (Re)démarre la surveillance et enregistre son échéance.
     *
     * Idempotent du côté de Google : rappeler `watch` sur une boîte déjà
     * surveillée remplace la surveillance existante et repousse l'échéance.
     */
    public function start(GoogleAccount $account): void
    {
        $topic = config('google.topic');

        if (empty($topic)) {
            throw new RuntimeException(
                'GOOGLE_PUBSUB_TOPIC absent : aucune notification de courrier '
                .'ne peut être reçue.',
            );
        }

        $response = $this->authorized($account)->post(self::BASE.'/watch', [
            'topicName' => $topic,
            // On ne surveille que la boîte de réception. Sans ce filtre, chaque
            // brouillon enregistré, chaque message envoyé et chaque libellé
            // posé déclencherait un avis — et donc une notification pour un
            // événement que la personne vient elle-même de provoquer.
            'labelIds' => ['INBOX'],
            'labelFilterBehavior' => 'INCLUDE',
        ]);

        if ($response->failed()) {
            throw new RuntimeException($this->reason($response->json()));
        }

        $account->update([
            'history_id' => (string) $response->json('historyId'),
            // Google rend l'échéance en millisecondes depuis l'époque.
            'watch_expires_at' => now()->setTimestamp(
                (int) ((int) $response->json('expiration') / 1000),
            ),
        ]);
        $account->clearError();
    }

    /** Arrête la surveillance. Appelée à la déconnexion. */
    public function stop(GoogleAccount $account): void
    {
        try {
            $this->authorized($account)->post(self::BASE.'/stop');
        } catch (Throwable) {
            // La déconnexion doit aboutir même si Google refuse : le jeton peut
            // avoir été révoqué depuis le compte Google, auquel cas la
            // surveillance est déjà morte.
        }
    }

    /**
     * Messages arrivés depuis le dernier `historyId` connu.
     *
     * Rend des métadonnées seulement — expéditeur, objet, identifiant de fil.
     * Le corps n'est jamais demandé : le serveur n'a besoin que de quoi écrire
     * une notification, et ne pas le lire est la meilleure garantie de ne pas
     * le stocker par accident.
     *
     * @return array<int, array{id: string, threadId: string, from: string, subject: string}>
     */
    public function newMessages(GoogleAccount $account): array
    {
        $depuis = $account->history_id;

        if ($depuis === null) {
            $this->resync($account);

            return [];
        }

        $response = $this->authorized($account)->get(self::BASE.'/history', [
            'startHistoryId' => $depuis,
            'historyTypes' => 'messageAdded',
            'labelId' => 'INBOX',
        ]);

        // Numéro trop ancien : la fenêtre d'historique de Google est passée.
        // On repart du présent plutôt que de boucler indéfiniment sur un
        // curseur que le serveur ne connaît plus.
        if ($response->status() === 404) {
            $this->resync($account);

            return [];
        }

        if ($response->failed()) {
            throw new RuntimeException($this->reason($response->json()));
        }

        $identifiants = collect($response->json('history') ?? [])
            ->flatMap(fn ($entry) => $entry['messagesAdded'] ?? [])
            ->pluck('message.id')
            ->filter()
            ->unique()
            // Une rafale d'arrivées ne doit pas produire vingt notifications
            // ni vingt appels à Gmail.
            ->take(5)
            ->values();

        // Le curseur avance même si aucun message ne nous intéresse : sinon le
        // même historique serait redemandé à chaque avis.
        $account->update([
            'history_id' => (string) ($response->json('historyId') ?? $depuis),
        ]);

        return $identifiants
            ->map(fn (string $id) => $this->metadata($account, $id))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Reprend le numéro d'historique courant sans rien notifier.
     */
    private function resync(GoogleAccount $account): void
    {
        $response = $this->authorized($account)->get(self::BASE.'/profile');

        if ($response->successful()) {
            $account->update([
                'history_id' => (string) $response->json('historyId'),
            ]);
        }
    }

    /**
     * En-têtes d'un message, sans son corps.
     *
     * @return array{id: string, threadId: string, from: string, subject: string}|null
     */
    private function metadata(GoogleAccount $account, string $id): ?array
    {
        $response = $this->authorized($account)->get(self::BASE."/messages/{$id}", [
            'format' => 'metadata',
            'metadataHeaders' => ['From', 'Subject'],
        ]);

        if ($response->failed()) {
            return null;
        }

        $entetes = collect($response->json('payload.headers') ?? [])
            ->mapWithKeys(fn ($h) => [strtolower($h['name'] ?? '') => $h['value'] ?? '']);

        // Un message que la personne s'envoie à elle-même, ou une copie de son
        // propre envoi remontée dans la boîte : ne pas la notifier de ce
        // qu'elle vient de faire.
        $expediteur = (string) $entetes->get('from', '');
        if (str_contains(strtolower($expediteur), strtolower($account->email))) {
            return null;
        }

        return [
            'id' => $id,
            'threadId' => (string) $response->json('threadId'),
            'from' => $this->displayName($expediteur),
            'subject' => (string) $entetes->get('subject', '(sans objet)'),
        ];
    }

    /**
     * « Fidèle Ondo <fidele@paynala.com> » → « Fidèle Ondo ».
     *
     * Le nom seul tient dans un bandeau de notification là où l'adresse
     * complète serait tronquée en plein milieu.
     */
    private function displayName(string $from): string
    {
        if (preg_match('/^\s*"?([^"<]+?)"?\s*<.+>\s*$/', $from, $m)) {
            return trim($m[1]);
        }

        return trim(str_replace(['<', '>'], '', $from)) ?: 'Expéditeur inconnu';
    }

    private function authorized(GoogleAccount $account): PendingRequest
    {
        return Http::withToken($this->oauth->accessToken($account->refresh_token))
            ->timeout(15);
    }

    /** @param  array<string, mixed>|null  $body */
    private function reason(?array $body): string
    {
        return $body['error']['message'] ?? 'erreur Gmail inconnue';
    }
}
