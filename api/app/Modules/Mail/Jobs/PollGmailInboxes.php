<?php

namespace App\Modules\Mail\Jobs;

use App\Models\User;
use App\Modules\Mail\Models\GoogleAccount;
use App\Modules\Mail\Services\GmailReader;
use App\Modules\Mail\Services\GoogleOAuth;
use App\Modules\Messagerie\Services\PushSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Relève les boîtes rattachées et notifie ce qui vient d'arriver.
 *
 * Planifié toutes les deux minutes. À cinq personnes, cela représente environ
 * 3 600 appels à Gmail par jour — très loin des quotas, et le jeton d'accès
 * étant mis en cache cinquante minutes, presque aucun échange de jetons.
 *
 * ## Ce qui ne doit jamais arriver
 *
 * Un compte en échec ne doit pas empêcher les autres d'être relevés : chacun est
 * traité dans son propre `try`. Et un compte dont l'autorisation a été révoquée
 * ne doit pas être réessayé toutes les deux minutes indéfiniment — sept cents
 * appels par jour qui échoueront tous de la même façon. D'où la mise en
 * quarantaine ci-dessous.
 */
class PollGmailInboxes implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Le travail entier est repris à la relève suivante, deux minutes plus
     * tard : le réessayer ici ne ferait que doubler les appels.
     */
    public int $tries = 1;

    /**
     * Durée de mise à l'écart d'un compte dont l'autorisation est perdue.
     *
     * `invalid_grant` signifie que le jeton de rafraîchissement ne vaut plus
     * rien — accès retiré depuis le compte Google, mot de passe changé. Seule
     * une reconnexion depuis l'app peut le réparer, et celle-ci efface l'erreur
     * et lève donc la quarantaine immédiatement. Insister entre-temps est
     * inutile.
     */
    private const QUARANTAINE_MINUTES = 60;

    public function handle(GmailReader $reader, GoogleOAuth $oauth, PushSender $push): void
    {
        GoogleAccount::query()->each(function (GoogleAccount $account) use ($reader, $oauth, $push) {
            if ($this->enQuarantaine($account)) {
                return;
            }

            try {
                $messages = $reader->newMessages($account);
            } catch (Throwable $e) {
                // Le jeton mis en cache peut avoir été révoqué avant son
                // expiration : on l'écarte pour que la relève suivante en
                // demande un neuf plutôt que de rejouer un jeton mort.
                $oauth->forgetToken($account->refresh_token);
                $account->recordError($e->getMessage());

                return;
            }

            $account->clearError();

            if ($messages !== []) {
                $this->notify($push, $account, $messages);
            }
        });
    }

    /**
     * Un compte dont l'autorisation est définitivement perdue est laissé de
     * côté un moment.
     */
    private function enQuarantaine(GoogleAccount $account): bool
    {
        $erreur = $account->last_error;
        $quand = $account->last_error_at;

        if ($erreur === null || $quand === null) {
            return false;
        }

        $perdue = str_contains($erreur, 'invalid_grant')
            || str_contains($erreur, 'unauthorized_client')
            || str_contains($erreur, 'Invalid Credentials');

        return $perdue && $quand->diffInMinutes(now()) < self::QUARANTAINE_MINUTES;
    }

    /**
     * @param  array<int, array{threadId: string, from: string, subject: string}>  $messages
     */
    private function notify(PushSender $push, GoogleAccount $account, array $messages): void
    {
        // La préférence est relue à chaque envoi plutôt que mise en cache : on
        // coupe ses notifications au moment où elles dérangent, et l'effet doit
        // être immédiat.
        $veutRecevoir = User::where('id', $account->user_id)
            ->where('notify_mail', true)
            ->exists();

        if (! $veutRecevoir) {
            return;
        }

        // Une rafale se résume en une seule notification : cinq bandeaux
        // d'affilée sont ignorés en bloc, là où un seul est lu.
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
