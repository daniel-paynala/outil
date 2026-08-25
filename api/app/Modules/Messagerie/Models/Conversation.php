<?php

namespace App\Modules\Messagerie\Models;

use App\Models\User;
use App\Modules\Core\Models\Project;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    use HasUuids;

    protected $fillable = [
        'project_id',
        'name',
        'topic',
        'is_group',
        'created_by',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'is_group' => 'boolean',
            'last_message_at' => 'datetime',
        ];
    }

    /**
     * Le dernier message n'est volontairement pas une relation.
     *
     * `hasOne()->latestOfMany()` serait le réflexe, mais il agrège avec
     * `MAX()` sur la clé primaire — et Postgres n'a pas de `max(uuid)`.
     * `latestOfMany('created_at')` passerait la compilation, mais les
     * timestamps Laravel sont à la seconde : deux messages simultanés
     * rendraient le résultat non déterministe. Le contrôleur le charge donc
     * par un `distinct on`, en une requête pour toute la liste.
     */
    public function members(): HasMany
    {
        return $this->hasMany(ConversationMember::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function hasMember(string $userId): bool
    {
        return $this->members()->where('user_id', $userId)->exists();
    }
}
