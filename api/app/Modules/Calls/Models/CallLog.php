<?php

namespace App\Modules\Calls\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un appel passé.
 *
 * Écrit par l'appelant seul, lu par les deux. Faire écrire les deux côtés
 * produirait deux lignes pour une même conversation.
 */
class CallLog extends Model
{
    use HasUuids;

    protected $fillable = [
        'caller_id',
        'callee_id',
        'connected_at',
        'duration',
        'end_reason',
        'route',
    ];

    protected function casts(): array
    {
        return [
            'connected_at' => 'datetime',
            'duration' => 'integer',
        ];
    }

    public function caller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caller_id');
    }

    public function callee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'callee_id');
    }
}
