<?php

namespace App\Modules\Notifications\Services;

use App\Models\Notification;
use App\Modules\Core\Models\Project;
use App\Modules\Messagerie\Services\PushSender;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Les notifications de l'application.
 *
 * ## Elles ne partaient pas sur les téléphones
 *
 * Ce service n'écrivait qu'une ligne en base. Assignation d'une tâche, mention
 * dans un commentaire, document déposé : tout n'existait que dans le centre de
 * notifications, visible seulement de qui pensait à l'ouvrir. Autant dire
 * jamais, pour une information dont l'intérêt est justement d'arriver sans
 * qu'on la cherche.
 *
 * ## Le déclencheur reste seul juge
 *
 * Les préférences sont appliquées par un déclencheur Postgres, qui abandonne
 * silencieusement l'insertion quand la case est décochée. Le push suit donc la
 * base plutôt que de refaire le raisonnement : on relit ce qui a réellement été
 * inséré, et on n'envoie qu'à ceux-là.
 *
 * Deux implémentations de la même règle auraient fini par diverger — et c'est
 * la plus permissive qui aurait fait la loi.
 */
class NotificationService
{
    public function __construct(private readonly PushSender $push) {}

    /**
     * Notification adressée à un utilisateur précis.
     */
    public function forUser(
        string $userId,
        string $type,
        string $title,
        ?string $body = null,
        ?string $link = null,
        ?string $projectId = null,
        ?string $actorId = null,
        array $metadata = [],
    ): ?Notification {
        // Évite l'auto-notification
        if ($actorId && $actorId === $userId) {
            return null;
        }

        $notification = Notification::create([
            'user_id' => $userId,
            'project_id' => $projectId,
            'actor_id' => $actorId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'link' => $link,
            'metadata' => empty($metadata) ? null : $metadata,
            'created_at' => now(),
        ]);

        $this->pousser([$userId], $notification->id, $type, $title, $body, $link);

        return $notification;
    }

    /**
     * Envoie le push à ceux dont la ligne a survécu au déclencheur.
     *
     * @param  array<int, string>  $userIds
     * @param  string|array<int, string>  $ids  identifiants des lignes créées
     */
    private function pousser(
        array $userIds,
        string|array $ids,
        string $type,
        string $title,
        ?string $body,
        ?string $link,
    ): void {
        // Ceux dont la préférence a fait abandonner l'insertion n'ont pas de
        // ligne : ils n'ont pas non plus de push.
        $destinataires = Notification::whereIn('id', (array) $ids)
            ->pluck('user_id')
            ->intersect($userIds)
            ->values()
            ->all();

        if ($destinataires === []) {
            return;
        }

        $this->push->send(
            $destinataires,
            $title,
            // Un corps vide fait refuser la notification entière par OneSignal.
            // Le titre porte déjà l'essentiel : il sert de repli.
            $body !== null && trim($body) !== '' ? $body : $title,
            ['type' => $type, 'link' => $link],
        );
    }

    /**
     * Diffuse à tous les membres d'un projet (sauf un user à exclure, typiquement l'auteur).
     */
    public function forProjectMembers(
        string $projectId,
        string $type,
        string $title,
        ?string $body = null,
        ?string $link = null,
        ?string $actorId = null,
        array $metadata = [],
        ?string $exceptUserId = null,
    ): Collection {
        $project = Project::find($projectId);
        if (! $project) {
            return collect();
        }

        $memberIds = $project->projectMembers()
            ->pluck('user_id')
            ->filter(fn ($id) => $id !== $exceptUserId)
            ->values();

        if ($memberIds->isEmpty()) {
            return collect();
        }

        $now = now();
        $rows = $memberIds->map(fn ($uid) => [
            'id' => (string) Str::uuid(),
            'user_id' => $uid,
            'project_id' => $projectId,
            'actor_id' => $actorId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'link' => $link,
            'metadata' => empty($metadata) ? null : json_encode($metadata),
            'read_at' => null,
            'created_at' => $now,
        ])->all();

        Notification::insert($rows);

        $this->pousser(
            $memberIds->all(),
            array_column($rows, 'id'),
            $type,
            $title,
            $body,
            $link,
        );

        return collect($rows);
    }
}
