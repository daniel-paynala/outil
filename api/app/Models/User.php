<?php

namespace App\Models;

use App\Modules\Core\Models\Project;
use App\Modules\Core\Models\ProjectMember;
use App\Modules\Core\Services\SupabaseUserSync;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
        'avatar_path',
        'notify_messages',
        'notify_projects',
        'notify_tasks',
        'notify_task_assignment',
        'notify_mail',
        'notify_task_assignment_email',
        'notify_project_document_email',
    ];

    /** Préférences de notification push et in-app, par catégorie. */
    public const NOTIFICATION_PREFERENCES = [
        'notify_messages',
        'notify_projects',
        'notify_tasks',
        'notify_task_assignment',
        'notify_mail',
    ];

    protected $appends = ['avatar_url'];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'notify_messages' => 'boolean',
            'notify_projects' => 'boolean',
            'notify_tasks' => 'boolean',
            'notify_task_assignment' => 'boolean',
            'notify_mail' => 'boolean',
        ];
    }

    /**
     * URL publique de l'avatar (null si pas d'avatar).
     * Stockée dans Supabase Storage bucket public 'avatars'.
     */
    public function getAvatarUrlAttribute(): ?string
    {
        if (empty($this->avatar_path)) {
            return null;
        }

        return app(\App\Modules\Files\Services\SupabaseStorage::class)
            ->publicUrl($this->avatar_path);
    }

    /**
     * Setter explicite pour `notify_task_assignment_email`.
     * Voir Column.php : PDO_PGSQL bind les bool en int (0/1) ce que Postgres
     * 16 strict refuse. On force des littéraux 't'/'f'.
     */
    protected function notifyTaskAssignmentEmail(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => (bool) $value,
            set: fn ($value) => $value ? 't' : 'f',
        );
    }

    protected function notifyProjectDocumentEmail(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => (bool) $value,
            set: fn ($value) => $value ? 't' : 'f',
        );
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

    /**
     * Invalide l'instantané mis en cache par l'authentification.
     *
     * Le middleware garde une copie courte de chaque compte pour éviter un
     * aller-retour vers Francfort à chaque requête. Cette copie doit tomber dès
     * qu'Arche modifie la ligne — rôle, nom, avatar, préférences de
     * notification — sinon la personne verrait son propre changement ignoré
     * pendant une minute, ce qui se lit comme un bug.
     *
     * Accroché à l'événement du modèle plutôt qu'appelé depuis chaque
     * contrôleur : le prochain écran qui écrira dans `users` sera couvert sans
     * que personne ait à y penser.
     */
    protected static function booted(): void
    {
        static::saved(fn (self $user) => SupabaseUserSync::forget($user->id));
        static::deleted(fn (self $user) => SupabaseUserSync::forget($user->id));
    }
}
