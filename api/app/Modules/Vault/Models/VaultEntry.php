<?php

namespace App\Modules\Vault\Models;

use App\Models\User;
use App\Modules\Core\Models\Project;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class VaultEntry extends Model
{
    use HasUuids, Searchable, SoftDeletes;

    public function searchableAs(): string
    {
        return 'vault_entries';
    }

    /**
     * Only non-sensitive metadata is indexed — NEVER the encrypted secret.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'name' => $this->name,
            'category' => $this->category,
            'username' => $this->username,
            'notes' => $this->notes,
            'url' => $this->url,
            'updated_at' => $this->updated_at?->timestamp,
        ];
    }

    protected $fillable = [
        'project_id',
        'name',
        'category',
        'username',
        'secret',
        'notes',
        'url',
        'expires_at',
        'visibility',
        'created_by',
        'updated_by',
    ];

    /**
     * The secret is encrypted at rest via Laravel's cast (AES-256-CBC + HMAC,
     * key = APP_KEY). It's also hidden from default serialization so it only
     * leaks to responses when explicitly marked visible.
     */
    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'expires_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function accessLogs(): HasMany
    {
        return $this->hasMany(VaultAccessLog::class, 'entry_id')->orderByDesc('created_at');
    }

    /**
     * Liste blanche des users pouvant accéder à cette entry quand visibility=restricted.
     * Le créateur a toujours accès, indépendamment de cette liste.
     */
    public function allowedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'vault_entry_users', 'vault_entry_id', 'user_id')
            ->withTimestamps();
    }

    /**
     * Détermine si l'utilisateur a accès à cette entry.
     * - Créateur : toujours accès
     * - 'all' : tout le monde a accès
     * - 'restricted' : seulement les users explicitement listés
     *
     * Note : l'appelant doit avoir déjà vérifié que l'user est membre du projet.
     */
    public function isAccessibleBy(string $userId): bool
    {
        if ($this->created_by === $userId) {
            return true;
        }
        if ($this->visibility === 'all') {
            return true;
        }

        return $this->allowedUsers()->where('user_id', $userId)->exists();
    }
}
