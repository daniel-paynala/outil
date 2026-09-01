<?php

use App\Modules\Mail\Jobs\PollGmailInboxes;
use App\Modules\Monitoring\Services\ProbeRunner;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Relève des boîtes Gmail
|--------------------------------------------------------------------------
|
| Toutes les deux minutes. C'est ce qui remplace la veille poussée de Gmail :
| celle-ci exigeait d'accorder un rôle IAM à un compte de service de Google,
| que la règle « Partage restreint au domaine » de l'organisation refuse.
|
| Le détour s'est révélé meilleur que l'obstacle. Une veille poussée expire au
| bout de sept jours et, non renouvelée, s'éteint sans erreur ni trace — la
| panne exacte de la file d'attente d'Arche, celle qui coûte des jours à
| diagnostiquer. Une relève périodique n'a pas d'état caché : si elle s'arrête,
| `last_polled_at` cesse d'avancer et l'écran de réglages le montre.
|
| Coût : environ 3 600 appels Gmail par jour pour cinq boîtes, très loin des
| quotas. Le jeton d'accès étant mis en cache cinquante minutes, presque aucun
| échange de jetons.
|
| `withoutOverlapping` : une relève lente ne doit pas en croiser une autre et
| doubler les notifications. `onOneServer` prépare le cas d'un second
| conteneur de planification.
|
*/
Schedule::job(new PollGmailInboxes)
    ->everyTwoMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->name('releve-boites-gmail');

// Digest des notifications email de documents : flush toutes les minutes
// (l'option --debounce dans la commande gère le délai effectif de 5 min).
Schedule::command('documents:flush-notifications')
    ->everyMinute()
    ->withoutOverlapping(5);

/*
 * La supervision des bases.
 *
 * Toutes les minutes : c'est la cadence qui donne son sens au projet — être
 * averti pendant que ça arrive, pas le lendemain matin. Chaque sonde a son
 * plafond de huit secondes côté Postgres, donc une base lente ne décale pas les
 * autres.
 *
 * `withoutOverlapping` compte : une base injoignable fait attendre ses sondes
 * jusqu'au délai d'expiration, et sans cette garde une seconde exécution
 * partirait par-dessus la première.
 */
Schedule::call(fn () => app(ProbeRunner::class)->runAll())
    ->name('monitoring.probes')
    // Le nom doit précéder la garde : c'est lui qui sert de clé de verrou, et
    // sans lui Laravel ne sait pas ce qu'il empêche de recouvrir.
    ->withoutOverlapping()
    ->everyMinute();
