<?php

namespace App\Modules\Mail\Services;

use App\Modules\Mail\Models\GoogleAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Lecture de ce qui arrive dans une boîte Gmail.
 *
 * ## Pourquoi une relève périodique et non une veille poussée
 *
 * Gmail sait prévenir un serveur d'une arrivée, par `users.watch()` et un sujet
 * Pub/Sub. Cette voie a été abandonnée pour une raison de terrain : elle exige
 * d'accorder un rôle IAM à `gmail-api-push@system.gserviceaccount.com`, et
 * l'organisation Paynala applique la règle « Partage restreint au domaine »,
 * qui refuse tout principal hors de `paynala.com`. La lever demandait un droit
 * d'administration au niveau de l'organisation.
 *
 * Le détour s'est révélé meilleur que l'obstacle. La veille poussée expire au
 * bout de sept jours et, non renouvelée, **s'éteint sans erreur, sans avis et
 * sans trace** : les notifications cessent d'arriver et rien ne relie l'effet à
 * la cause. C'est exactement la panne qu'Arche a déjà connue avec sa file
 * d'attente. Une relève périodique n'a pas cet état caché — si elle s'arrête,
 * `last_polled_at` cesse d'avancer, et l'écran de réglages le montre.
 *
 * Ce qu'on y perd : jusqu'à deux minutes de délai sur une notification. Ce
 * qu'on y gagne : aucun sujet Pub/Sub, aucun abonnement, aucun point d'entrée
 * public, et rien qui expire.
 *
 * ## Le curseur
 *
 * `history_id` est un numéro de version de la boîte. On demande à Gmail ce qui
 * a changé depuis celui qu'on connaît, et on le fait avancer. Google ne garde
 * qu'une fenêtre glissante : un numéro trop ancien est refusé (404), et on
 * repart alors du présent — quelques notifications manquées valent mieux qu'une
 * relève qui ne redémarre jamais.
 */
class GmailReader
{
    private const BASE = 'https://gmail.googleapis.com/gmail/v1/users/me';

    /**
     * Nombre maximal de messages rapportés par relève.
     *
     * Une rafale d'arrivées ne doit produire ni vingt notifications ni vingt
     * appels à Gmail. Au-delà, le compte suffit à informer.
     */
    private const MAX_PAR_RELEVE = 5;

    public function __construct(private readonly GoogleOAuth $oauth) {}

    /**
     * Messages arrivés depuis la dernière relève.
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

        // Première relève d'une boîte tout juste rattachée : on prend le
        // numéro courant sans rien notifier. Sans cela, la connexion
        // déclencherait une notification pour chaque message déjà présent.
        if ($depuis === null) {
            $this->resync($account);

            return [];
        }

        $response = $this->authorized($account)->get(self::BASE.'/history', [
            'startHistoryId' => $depuis,
            'historyTypes' => 'messageAdded',
            'labelId' => 'INBOX',
        ]);

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
            ->take(self::MAX_PAR_RELEVE)
            ->values();

        // Le curseur avance même si aucun message ne nous intéresse : sinon le
        // même historique serait redemandé à chaque relève, indéfiniment.
        $account->update([
            'history_id' => (string) ($response->json('historyId') ?? $depuis),
            'last_polled_at' => now(),
        ]);

        return $identifiants
            ->map(fn (string $id) => $this->metadata($account, $id))
            ->filter()
            ->values()
            ->all();
    }

    /** Reprend le numéro d'historique courant sans rien notifier. */
    private function resync(GoogleAccount $account): void
    {
        $response = $this->authorized($account)->get(self::BASE.'/profile');

        if ($response->failed()) {
            throw new RuntimeException($this->reason($response->json()));
        }

        $account->update([
            'history_id' => (string) $response->json('historyId'),
            'last_polled_at' => now(),
        ]);
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

        // Un message qu'on s'envoie à soi-même, ou une copie de son propre
        // envoi remontée dans la boîte : ne pas notifier quelqu'un de ce qu'il
        // vient de faire.
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
