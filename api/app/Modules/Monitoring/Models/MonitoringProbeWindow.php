<?php

namespace App\Modules\Monitoring\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une durée d'observation et ses paliers, avec l'état du signalement.
 */
class MonitoringProbeWindow extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'probe_id', 'hours', 'tiers', 'highest_tier', 'last_value', 'last_run_at',
    ];

    protected function casts(): array
    {
        return [
            'tiers' => 'array',
            'hours' => 'integer',
            'highest_tier' => 'integer',
            'last_value' => 'integer',
            'last_run_at' => 'datetime',
        ];
    }

    public function probe(): BelongsTo
    {
        return $this->belongsTo(MonitoringProbe::class, 'probe_id');
    }

    /** @return array<int, int> croissants, quel que soit l'ordre de saisie */
    public function sortedTiers(): array
    {
        $paliers = array_values(array_filter(
            array_map('intval', $this->tiers ?? []),
            fn (int $p) => $p > 0,
        ));
        sort($paliers);

        return $paliers;
    }
}
