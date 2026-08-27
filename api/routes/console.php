<?php

use App\Modules\Mail\Jobs\RenewGmailWatches;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Renouvellement des surveillances Gmail
|--------------------------------------------------------------------------
|
| Google limite une surveillance à sept jours. Non renouvelée, elle s'éteint
| sans erreur, sans avis et sans trace : les notifications de courrier cessent
| simplement d'arriver, et rien ne relie l'effet à la cause.
|
| Quotidien, donc sept fois plus fréquent que nécessaire — un échec isolé, ou
| même six jours d'affilée, reste sans conséquence. C'est délibéré : le coût
| d'un appel de trop est nul, celui d'une extinction silencieuse se compte en
| jours de recherche.
|
| `withoutOverlapping` évite qu'un renouvellement lent en croise un autre ;
| `onOneServer` prépare le cas où un second conteneur de planification serait
| ajouté, ce qui doublerait sinon les appels à Google.
|
*/
Schedule::job(new RenewGmailWatches)
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->name('renouvellement-surveillances-gmail');
