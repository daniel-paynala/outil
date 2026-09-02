<?php

namespace App\Models;

use App\Models\Concerns\StoresPostgresBoolean;
use App\Modules\Core\Models\Project;
use App\Modules\Core\Models\ProjectMember;
use App\Modules\Core\Services\SupabaseUserSync;
use App\Modules\Files\Services\SupabaseStorage;
use App\Modules\Monitoring\Support\Capability;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class User extends Model
{
    use HasUuids;
    use StoresPostgresBoolean;

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
        'mail_signature',
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

        return app(SupabaseStorage::class)
            ->publicUrl($this->avatar_path);
    }

    /** Voir `StoresPostgresBoolean`. */
    protected function notifyTaskAssignmentEmail(): Attribute
    {
        return $this->postgresBoolean();
    }

    protected function notifyProjectDocumentEmail(): Attribute
    {
        return $this->postgresBoolean();
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

    /**
     * Les droits accordés à cette personne, droits impliqués compris.
     *
     * ## Pourquoi un administrateur les a tous
     *
     * Sans cette règle, accorder un droit exigerait déjà de l'avoir — et
     * personne ne pourrait accorder le premier. L'administrateur est la racine
     * de la chaîne, comme il l'est déjà pour le reste d'Arche.
     *
     * @return array<int, string>
     */
    /**
     * Mémorisé pour la durée de la requête HTTP.
     *
     * `isVisibleTo()` est appelé une fois par sonde, et chaque appel
     * interrogeait `user_capabilities`. Vingt sondes faisaient vingt
     * allers-retours vers Francfort pour une réponse qui ne peut pas changer
     * entre le début et la fin d'un même affichage.
     *
     * L'instantané ne survit pas à la requête : accorder un droit crée une
     * nouvelle instance au prochain appel, et le contrôleur qui les modifie
     * écrit directement en base sans passer par ici.
     */
    private ?array $capabilitiesCache = null;

    public function capabilities(): array
    {
        if ($this->capabilitiesCache !== null) {
            return $this->capabilitiesCache;
        }

        return $this->capabilitiesCache = $this->computeCapabilities();
    }

    private function computeCapabilities(): array
    {
        if ($this->isAdmin()) {
            return array_map(fn (Capability $c) => $c->value, Capability::cases());
        }

        $accordes = $this->relationLoaded('grantedCapabilities')
            ? $this->grantedCapabilities->pluck('capability')->all()
            : DB::table('user_capabilities')
                ->where('user_id', $this->id)
                ->pluck('capability')
                ->all();

        $effectifs = [];
        foreach ($accordes as $valeur) {
            $droit = Capability::tryFrom($valeur);
            if ($droit === null) {
                continue;
            }

            $effectifs[] = $droit->value;
            foreach ($droit->implies() as $implique) {
                $effectifs[] = $implique->value;
            }
        }

        return array_values(array_unique($effectifs));
    }

    public function can(Capability $capability): bool
    {
        return in_array($capability->value, $this->capabilities(), true);
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
