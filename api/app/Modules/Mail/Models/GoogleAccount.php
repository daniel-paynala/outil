<?php

namespace App\Modules\Mail\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Boîte Google Workspace rattachée à un compte Arche.
 *
 * @property string $id
 * @property string $user_id
 * @property string $email
 * @property string $refresh_token
 */
class GoogleAccount extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'email',
        'refresh_token',
        'scopes',
        'history_id',
        'last_polled_at',
        'last_error',
        'last_error_at',
    ];

    /**
     * `refresh_token` n'apparaît jamais dans une réponse JSON.
     *
     * Ce n'est pas une précaution de forme : ce jeton donne un accès permanent
     * à la boîte de quelqu'un, et le rendre par mégarde — un `return $account`
     * dans un contrôleur — le distribuerait à l'app, aux journaux et à tout ce
     * qui sérialise le modèle.
     */
    protected $hidden = ['refresh_token'];

    protected function casts(): array
    {
        return [
            // Chiffré au repos avec l'APP_KEY : une fuite de la base seule ne
            // donne rien d'exploitable.
            'refresh_token' => 'encrypted',
            'last_polled_at' => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * La relève tourne-t-elle ?
     *
     * Le seuil est volontairement large au regard de l'intervalle de deux
     * minutes : un planificateur redémarré, une relève un peu longue ou une
     * heure creuse ne doivent pas faire clignoter l'écran de réglages. Passé
     * un quart d'heure sans relève, en revanche, quelque chose est cassé —
     * planificateur arrêté, file bloquée — et il faut le dire.
     */
    public function pollingHealthy(): bool
    {
        return $this->last_polled_at !== null
            && $this->last_polled_at->diffInMinutes(now()) < 15;
    }

    public function recordError(string $message): void
    {
        $this->update([
            'last_error' => mb_substr($message, 0, 500),
            'last_error_at' => now(),
        ]);
    }

    public function clearError(): void
    {
        if ($this->last_error !== null) {
            $this->update(['last_error' => null, 'last_error_at' => null]);
        }
    }
}
