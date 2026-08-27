<?php

namespace App\Modules\Mail\Jobs;

use App\Models\User;
use App\Modules\Mail\Models\GoogleAccount;
use App\Modules\Mail\Services\GmailWatcher;
use App\Modules\Messagerie\Services\PushSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Notifie l'arrivée de courrier, à la réception d'un avis Gmail.
 *
 * Hors du cycle de la requête pour deux raisons : Pub/Sub retente tout ce qui
 * ne répond pas en 2xx rapidement, et ce travail demande plusieurs
 * allers-retours à Google.
 */
class NotifyNewMail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Trois essais, espacés.
     *
     * Un jeton d'accès expiré ou une limite de débit passagère se règlent
     * d'eux-mêmes ; s'acharner davantage sur une autorisation révoquée ne
     * changerait rien.
     */
    public int $tries = 3;

    public array $backoff = [10, 60];

    public function __construct(private readonly string $accountId) {}

    public function handle(GmailWatcher $watcher, PushSender $push): void
    {
        $account = GoogleAccount::find($this->accountId);

        if ($account === null) {
            return;
        }

        try {
            $messages = $watcher->newMessages($account);
        } catch (Throwable $e) {
            // Consigné sur le compte, donc visible dans l'écran de réglages :
            // un jeton révoqué côté Google ne se remarquerait sinon que par
            // l'absence de notifications, des jours plus tard.
            $account->recordError($e->getMessage());
            throw $e;
        }

        $account->clearError();

        if ($messages === []) {
            return;
        }

        // La préférence est relue à chaque envoi plutôt que mise en cache : on
        // coupe ses notifications au moment où elles dérangent, et l'effet doit
        // être immédiat.
        $veutRecevoir = User::where('id', $account->user_id)
            ->where('notify_mail', true)
            ->exists();

        if (! $veutRecevoir) {
            return;
        }

        // Une rafale d'arrivées se résume en une seule notification. Cinq
        // bandeaux d'affilée sont ignorés en bloc, là où un seul est lu.
        if (count($messages) > 1) {
            $push->send(
                [$account->user_id],
                count($messages).' nouveaux messages',
                collect($messages)->pluck('from')->unique()->take(3)->join(', '),
                ['type' => 'mail'],
            );

            return;
        }

        $message = $messages[0];

        $push->send(
            [$account->user_id],
            $message['from'],
            $message['subject'],
            // Ouvre le fil concerné plutôt que la boîte : le tap doit mener où
            // la notification promettait.
            ['type' => 'mail', 'thread_id' => $message['threadId']],
        );
    }
}
