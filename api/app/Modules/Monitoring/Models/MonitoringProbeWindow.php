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
        'probe_id', 'hours', 'mode', 'tiers', 'highest_tier', 'last_value', 'last_run_at',
    ];

    /**
     * Le défaut est porté ici en plus de la colonne.
     *
     * Sans cela, une fenêtre tout juste créée sans `mode` rend `null` en
     * mémoire jusqu'au premier `fresh()` — et le code qui lit `mode` juste
     * après la création verrait une valeur que la base n'a jamais eue.
     */
    protected $attributes = ['mode' => 'glissante'];

    /**
     * Une journée calendaire ne se découpe qu'en journées entières.
     *
     * Une fenêtre de 6 h « depuis minuit » n'a pas de sens : minuit moins
     * six heures, c'est hier soir, et la fenêtre changerait de longueur au fil
     * de la journée. Le contrôleur refuse donc ce cas plutôt que de rendre un
     * chiffre que personne ne saurait interpréter.
     */
    public function isCalendar(): bool
    {
        return $this->mode === 'calendaire';
    }

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
