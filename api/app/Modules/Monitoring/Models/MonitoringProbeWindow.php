<?php

namespace App\Modules\Monitoring\Models;

use App\Modules\Monitoring\Support\Direction;
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
        'probe_id', 'hours', 'mode', 'direction', 'tiers',
        'severest_tier', 'last_value', 'last_detail', 'last_run_at',
    ];

    /**
     * Le défaut est porté ici en plus de la colonne.
     *
     * Sans cela, une fenêtre tout juste créée sans `mode` rend `null` en
     * mémoire jusqu'au premier `fresh()` — et le code qui lit `mode` juste
     * après la création verrait une valeur que la base n'a jamais eue.
     */
    protected $attributes = [
        'mode' => 'glissante',
        // Le sens par défaut est celui de toutes les sondes existantes : le
        // danger est en haut. Une fenêtre déjà réglée ne doit pas changer de
        // sens sous les pieds de qui a posé ses paliers.
        'direction' => 'croissant',
    ];

    /** Dans quel sens cette fenêtre se dégrade. */
    public function direction(): Direction
    {
        return Direction::tryFrom($this->direction ?? '') ?? Direction::Croissant;
    }

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

    /**
     * La période, telle qu'elle se dit dans une notification.
     *
     * « sur 24 h » ne veut rien dire d'une fenêtre mensuelle, et « sur 720 h »
     * encore moins : un mois n'a pas une durée fixe.
     */
    public function periodLabel(): string
    {
        return match ($this->mode) {
            'mensuelle' => 'ce mois-ci',
            'annuelle' => 'cette année',
            'totale' => 'depuis toujours',
            'calendaire' => $this->hours <= 24
                ? "aujourd'hui"
                : 'sur '.intdiv($this->hours, 24).' jours',
            default => "sur {$this->hours} h",
        };
    }

    /** Les modes dont la durée en heures ne veut rien dire. */
    public function ignoresHours(): bool
    {
        return in_array($this->mode, ['mensuelle', 'annuelle', 'totale'], true);
    }

    protected function casts(): array
    {
        return [
            'tiers' => 'array',
            'last_detail' => 'array',
            'hours' => 'integer',
            'severest_tier' => 'integer',
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
