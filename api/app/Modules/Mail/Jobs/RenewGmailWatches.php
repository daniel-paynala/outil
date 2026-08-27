<?php

namespace App\Modules\Mail\Jobs;

use App\Modules\Mail\Models\GoogleAccount;
use App\Modules\Mail\Services\GmailWatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Renouvelle les surveillances Gmail avant leur expiration.
 *
 * Google les limite à sept jours. Non renouvelée, une surveillance s'éteint
 * **sans erreur, sans avis et sans trace** : les notifications cessent
 * simplement d'arriver, et rien ne relie l'effet à la cause. C'est exactement
 * la panne muette qu'Arche a déjà connue avec sa file d'attente — plusieurs
 * jours perdus à chercher du côté du fournisseur de push alors que le
 * consommateur avait disparu.
 *
 * D'où deux garde-fous : un renouvellement quotidien, bien plus fréquent que
 * nécessaire pour qu'un échec isolé n'ait aucune conséquence, et l'échéance
 * exposée dans l'écran de réglages pour que l'extinction se voie.
 */
class RenewGmailWatches implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(GmailWatcher $watcher): void
    {
        GoogleAccount::query()
            // Un renouvellement raté ne doit pas empêcher les suivants : on
            // traite compte par compte, chacun dans son propre `try`.
            ->orderBy('watch_expires_at')
            ->each(function (GoogleAccount $account) use ($watcher) {
                if (! $account->watchNeedsRenewal()) {
                    return;
                }

                try {
                    $watcher->start($account);
                } catch (Throwable $e) {
                    $account->recordError($e->getMessage());
                    Log::warning('Surveillance Gmail non renouvelée', [
                        'compte' => $account->email,
                        'motif' => $e->getMessage(),
                    ]);
                }
            });
    }
}
