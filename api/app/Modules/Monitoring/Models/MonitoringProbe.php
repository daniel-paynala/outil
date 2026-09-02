<?php

namespace App\Modules\Monitoring\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une sonde : une requête qui rend un nombre, comparé à des paliers.
 */
class MonitoringProbe extends Model
{
    use HasUuids;

    protected $fillable = [
        'database_id', 'title', 'unit', 'query',
        'counting_from', 'acknowledged_by', 'enabled', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'counting_from' => 'datetime',
        ];
    }

    public function database(): BelongsTo
    {
        return $this->belongsTo(MonitoredDatabase::class, 'database_id');
    }

    public function windows(): HasMany
    {
        return $this->hasMany(MonitoringProbeWindow::class, 'probe_id');
    }

    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    /**
     * Un incident est-il en cours ?
     *
     * Un palier signalé et non acquitté. Ce n'est pas déductible du compte
     * courant : celui-ci peut être redescendu sans que personne n'ait rien fait
     * — et c'est précisément le cas où il ne faut pas refermer tout seul.
     */
    public function hasOpenIncident(): bool
    {
        return $this->windows->contains(fn ($w) => $w->severest_tier > 0);
    }
}
