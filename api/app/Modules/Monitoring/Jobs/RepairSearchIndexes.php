<?php

namespace App\Modules\Monitoring\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Remet le moteur de recherche en accord avec le dépôt.
 *
 * ## Pourquoi en file, et non dans la requête
 *
 * La synchronisation des réglages était d'abord exécutée en direct : ce ne sont
 * que quelques appels au moteur, et la requête paraissait devoir tenir. Elle ne
 * tenait pas. Cinq index, chacun avec ses attributs filtrables et triables, et
 * un moteur occupé : PHP-FPM coupait avant la fin, le proxy renvoyait un 502
 * sans corps, et **rien n'était journalisé** — le processus mourait avant
 * d'avoir pu écrire quoi que ce soit.
 *
 * Une réparation qui échoue sans laisser de trace est pire qu'une panne, parce
 * qu'on la relance indéfiniment. Tout passe donc par la file, dont les travaux
 * n'ont pas d'échéance et dont les échecs atterrissent dans `failed_jobs`.
 *
 * @param  array<int, class-string>  $models  modèles à réindexer entièrement
 */
class RepairSearchIndexes implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Un seul essai.
     *
     * Rejouer une synchronisation qui a échoué la referait échouer de la même
     * façon — la cause est une configuration, pas un aléa. Et l'échec est
     * visible : la sonde reste rouge.
     */
    public int $tries = 1;

    /** @param  array<int, class-string>  $models */
    public function __construct(private readonly array $models = []) {}

    public function handle(): void
    {
        // Les réglages d'abord : un index repeuplé mais mal réglé reste
        // inutilisable, et l'ordre inverse gâcherait tout le travail d'import.
        Artisan::call('scout:sync-index-settings');
        Log::info('Réglages des index synchronisés', [
            'sortie' => trim(Artisan::output()),
        ]);

        foreach ($this->models as $model) {
            Artisan::call('scout:import', ['model' => $model]);
            Log::info('Index repeuplé', [
                'modele' => $model,
                'sortie' => trim(Artisan::output()),
            ]);
        }
    }
}
