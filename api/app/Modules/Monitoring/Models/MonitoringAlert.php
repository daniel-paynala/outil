<?php

namespace App\Modules\Monitoring\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un palier franchi, consigné.
 *
 * Ce n'est pas redondant avec l'état de la fenêtre : l'état dit où l'on en est,
 * le journal dit comment on y est arrivé — à quelle vitesse, et combien de fois
 * ce mois-ci.
 */
class MonitoringAlert extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['probe_id', 'window_hours', 'tier', 'value', 'raised_at'];

    protected function casts(): array
    {
        return ['raised_at' => 'datetime', 'tier' => 'integer', 'value' => 'integer'];
    }

    public function probe(): BelongsTo
    {
        return $this->belongsTo(MonitoringProbe::class, 'probe_id');
    }
}
