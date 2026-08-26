<?php

namespace App\Models;

use App\Modules\Core\Models\Project;
use App\Modules\Core\Models\ProjectMember;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'email',
        'name',
        'role',
        'metadata',
        'notify_messages',
        'notify_projects',
        'notify_tasks',
        'notify_task_assignment',
    ];

    /** Préférences de notification push et in-app, par catégorie. */
    public const NOTIFICATION_PREFERENCES = [
        'notify_messages',
        'notify_projects',
        'notify_tasks',
        'notify_task_assignment',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'notify_messages' => 'boolean',
            'notify_projects' => 'boolean',
            'notify_tasks' => 'boolean',
            'notify_task_assignment' => 'boolean',
        ];
    }

    public function ownedProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'created_by');
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }
}
