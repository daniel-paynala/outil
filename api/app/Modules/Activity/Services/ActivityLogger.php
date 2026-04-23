<?php

namespace App\Modules\Activity\Services;

use App\Modules\Activity\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

class ActivityLogger
{
    /**
     * Record a cross-module activity event on a project's timeline.
     * Keep this light & synchronous; consumers should pass an existing actor id.
     */
    public function log(
        string $projectId,
        ?string $actorId,
        string $action,
        ?Model $subject = null,
        ?string $subjectName = null,
        array $metadata = [],
    ): ActivityLog {
        $subjectType = $subject ? $this->typeFor($subject) : null;
        $subjectId = $subject?->getKey();
        $derivedName = $subjectName
            ?? ($subject?->title ?? $subject?->name ?? null);

        return ActivityLog::create([
            'project_id' => $projectId,
            'actor_id' => $actorId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'subject_name' => $derivedName,
            'metadata' => empty($metadata) ? null : $metadata,
            'created_at' => now(),
        ]);
    }

    private function typeFor(Model $subject): string
    {
        return match (class_basename($subject)) {
            'Card' => 'card',
            'Column' => 'column',
            'Label' => 'label',
            'DocPage' => 'doc_page',
            'ProjectFile' => 'file',
            'VaultEntry' => 'vault_entry',
            'Decision' => 'decision',
            'TimeEntry' => 'time_entry',
            'Project' => 'project',
            'ProjectMember' => 'project_member',
            default => strtolower(class_basename($subject)),
        };
    }
}
