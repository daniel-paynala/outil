<?php

namespace App\Modules\Monitoring\Models;

use App\Models\User;
use App\Modules\Monitoring\Support\Capability;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une sonde : une requête qui rend un nombre, comparé à des paliers.
 */
class MonitoringProbe extends Model
{
    use HasUuids;

    protected $attributes = [
        'timeout_ms' => 8000,
        'interval_minutes' => 1,
    ];

    protected $fillable = [
        'database_id', 'title', 'unit', 'query', 'timeout_ms', 'interval_minutes',
        'counting_from', 'acknowledged_by', 'enabled', 'last_error', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'counting_from' => 'datetime',
        ];
    }

    /**
     * Les personnes à qui cette sonde est restreinte.
     *
     * **Vide veut dire « tout le monde »**, pas « personne ». La restriction
     * est une exception qu'on pose, jamais un réglage qu'on oublie : l'absence
     * de ligne ne peut pas cacher une sonde par accident.
     */
    public function viewers(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'monitoring_probe_viewers',
            'probe_id',
            'user_id',
        )->withPivot(['granted_by', 'granted_at']);
    }

    public function isRestricted(): bool
    {
        return $this->relationLoaded('viewers')
            ? $this->viewers->isNotEmpty()
            : $this->viewers()->exists();
    }

    /**
     * Cette personne peut-elle voir cette sonde ?
     *
     * Les porteurs de `monitoring.admin` voient tout. Ce n'est pas une faveur :
     * ils peuvent modifier n'importe quelle sonde, y compris sa requête, et
     * l'exécuter par le bouton « Essayer ». Leur masquer le résultat pendant
     * qu'ils gardent le moyen de l'obtenir ne serait pas de la confidentialité,
     * seulement une gêne — et une gêne qu'on croit être une protection est pire
     * que pas de protection du tout.
     *
     * La restriction porte donc sur les membres à qui l'on a accordé la simple
     * consultation.
     */
    public function isVisibleTo(User $user): bool
    {
        if ($user->can(Capability::MonitoringAdmin)) {
            return true;
        }

        $restreinte = $this->relationLoaded('viewers')
            ? $this->viewers
            : $this->viewers()->get();

        return $restreinte->isEmpty()
            || $restreinte->contains(fn (User $u) => $u->id === $user->id);
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
    /**
     * Est-il temps de la relancer ?
     *
     * Onze sondes qui mettent quarante-cinq secondes, toutes les minutes, ne
     * tiennent pas : elles se chevauchent, la garde les empêche de partir, et
     * la supervision décroche sans rien dire. Un cumul mensuel n'a aucun
     * besoin de tourner chaque minute — son chiffre bouge de quelques francs.
     * Une sonde de time-outs, si.
     */
    public function isDue(): bool
    {
        return $this->windows->contains(
            fn (MonitoringProbeWindow $f) => $f->isDue($this->interval_minutes ?? 1),
        );
    }

    public function hasOpenIncident(): bool
    {
        return $this->windows->contains(fn ($w) => $w->severest_tier > 0);
    }
}
