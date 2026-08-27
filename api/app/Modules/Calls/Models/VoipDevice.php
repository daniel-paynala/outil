<?php

namespace App\Modules\Calls\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un appareil joignable par push VoIP.
 *
 * Plusieurs par personne : quelqu'un peut avoir un iPhone et un iPad, et une
 * réinstallation en crée un nouveau sans invalider l'ancien immédiatement. On
 * fait donc sonner tous les appareils connus, et on retire ceux qu'Apple
 * déclare morts.
 */
class VoipDevice extends Model
{
    use HasUuids;

    protected $fillable = ['user_id', 'token', 'platform', 'last_used_at'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
