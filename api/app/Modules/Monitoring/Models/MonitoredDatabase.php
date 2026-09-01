<?php

namespace App\Modules\Monitoring\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une base de production surveillée.
 *
 * ## Le mot de passe ne repart jamais
 *
 * Il est chiffré au repos par le cast de Laravel — même mécanisme que le
 * coffre — et `$hidden` l'exclut de toute sérialisation. L'application affiche
 * l'hôte et l'utilisateur ; le mot de passe s'écrit une fois et ne se relit
 * plus, même par qui l'a saisi.
 */
class MonitoredDatabase extends Model
{
    use HasUuids;

    protected $fillable = [
        'name', 'host', 'port', 'dbname', 'username', 'password',
        'read_only_verified_at', 'last_error', 'created_by',
    ];

    /**
     * Jamais sérialisé, dans aucune réponse.
     *
     * La liste des bases se consulte depuis l'application ; en laissant fuir
     * cette colonne, une seule requête donnerait les clés de toutes les bases
     * de production de l'entreprise.
     */
    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'port' => 'integer',
            'read_only_verified_at' => 'datetime',
        ];
    }

    public function probes(): HasMany
    {
        return $this->hasMany(MonitoringProbe::class, 'database_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * La base est-elle utilisable ?
     *
     * Tant que la lecture seule n'a pas été constatée, on ne l'interroge pas :
     * une base ajoutée avec des identifiants trop puissants doit rester inerte
     * plutôt que d'être surveillée « en attendant ».
     */
    public function isUsable(): bool
    {
        return $this->read_only_verified_at !== null;
    }
}
