<?php

namespace App\Modules\Monitoring\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * État de la file d'attente.
 *
 * Un job qui n'est jamais consommé ne produit **rien** : ni erreur, ni entrée
 * dans `failed_jobs`, ni ligne de log. Si le worker s'arrête — conteneur
 * absent, unité systemd oubliée, migration d'architecture — les jobs
 * s'empilent en silence et le seul symptôme est l'absence de ce qu'ils
 * devaient produire. On cherche alors du mauvais côté pendant des heures.
 *
 * Cette sonde répond à la seule question qui compte : *quelqu'un consomme-t-il
 * la file ?*
 */
class QueueHealthController extends Controller
{
    /** Clé du dernier job traité, alimentée par le crochet d'AppServiceProvider. */
    public const LAST_PROCESSED_KEY = 'arche.queue.last_processed_at';

    /**
     * Au-delà, une file non vide signifie que personne ne la consomme.
     *
     * Cinq minutes : assez pour absorber un redémarrage de worker
     * (`--max-time=3600` en relance un régulièrement) sans laisser passer une
     * panne réelle.
     */
    private const STALE_AFTER_SECONDS = 300;

    public function show(): JsonResponse
    {
        $pending = $this->pending();
        $failed = $this->failed();
        $lastProcessed = Cache::get(self::LAST_PROCESSED_KEY);
        $secondsSince = $lastProcessed === null
            ? null
            : max(0, now()->timestamp - (int) $lastProcessed);

        return response()->json([
            'connection' => config('queue.default'),
            'pending' => $pending,
            'failed' => $failed,
            'last_processed_at' => $lastProcessed === null
                ? null
                : now()->setTimestamp((int) $lastProcessed)->toIso8601String(),
            'seconds_since_last_processed' => $secondsSince,
            'status' => $this->verdict($pending, $failed, $secondsSince),
        ]);
    }

    /**
     * Trois états seulement, pour que le client n'ait pas à réinterpréter.
     *
     * Une file vide ne prouve pas qu'un worker tourne — elle prouve seulement
     * qu'il n'y a rien à faire. On ne crie donc pas au loup dans ce cas : on
     * dit « ok » et on laisse `last_processed_at` parler.
     */
    private function verdict(int $pending, int $failed, ?int $secondsSince): string
    {
        $stale = $secondsSince === null || $secondsSince > self::STALE_AFTER_SECONDS;

        if ($pending > 0 && $stale) {
            return 'down';
        }
        if ($failed > 0) {
            return 'warn';
        }

        return 'ok';
    }

    /**
     * Vide la liste des traitements en échec.
     *
     * Un échec ancien — celui d'une table qui n'existait pas encore, d'une
     * configuration depuis corrigée — maintient la sonde en « dégradé » pour
     * toujours. Une alerte qui ne s'éteint jamais cesse d'être lue, et masque
     * la suivante.
     *
     * Rend le nombre effacé, pour qu'on sache ce qu'on vient de perdre.
     */
    public function flush(): JsonResponse
    {
        try {
            $avant = DB::table('failed_jobs')->count();
            Artisan::call('queue:flush');

            return response()->json([
                'cleared' => $avant,
                'message' => $avant === 0
                    ? 'Aucun échec à effacer.'
                    : "{$avant} échec".($avant > 1 ? 's effacés' : ' effacé').'.',
            ]);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /** Jobs en attente, quel que soit le pilote — Redis en prod, base en local. */
    private function pending(): int
    {
        try {
            return Queue::size();
        } catch (Throwable) {
            // File injoignable (Redis éteint) : on ne casse pas la sonde, dont
            // le rôle est justement de rapporter les pannes.
            return -1;
        }
    }

    private function failed(): int
    {
        try {
            return DB::table('failed_jobs')->count();
        } catch (Throwable) {
            return -1;
        }
    }
}
