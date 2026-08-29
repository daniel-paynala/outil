<?php

namespace App\Modules\Notifications\Services;

use App\Models\Notification;
use App\Modules\Core\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class NotificationService
{
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

        return Notification::create([
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

        return collect($rows);
    }
}
