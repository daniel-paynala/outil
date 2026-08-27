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
        'watch_expires_at',
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
            'watch_expires_at' => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * La surveillance Gmail est-elle encore active ?
     *
     * Google la limite à 7 jours. On considère qu'elle est à renouveler bien
     * avant l'échéance — un renouvellement raté doit pouvoir être retenté
     * plusieurs fois avant que les notifications ne s'arrêtent réellement.
     */
    public function watchNeedsRenewal(): bool
    {
        return $this->watch_expires_at === null
            || $this->watch_expires_at->subDay()->isPast();
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
