<?php

namespace App\Modules\Monitoring\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Adr\Models\Decision;
use App\Modules\Docs\Models\DocPage;
use App\Modules\Files\Models\ProjectFile;
use App\Modules\Monitoring\Jobs\ReindexModel;
use App\Modules\Tasks\Models\Card;
use App\Modules\Vault\Models\VaultEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Meilisearch\Client;
use Throwable;

/**
 * État du moteur de recherche, index par index.
 *
 * ## Pourquoi cette sonde existe
 *
 * La recherche globale est restée cassée en production sans que rien ne le
 * dise. Trois causes possibles se ressemblaient de l'extérieur — toutes
 * donnaient le même écran vide, ou la même erreur 500 sans explication :
 *
 *  1. le moteur est éteint ou injoignable ;
 *  2. l'index n'a jamais été peuplé (`scout:import` jamais lancé) ;
 *  3. l'index existe et contient des documents, mais ses *réglages* ne sont
 *     pas ceux du dépôt — c'était le cas réel : `config/scout.php` déclarait
 *     `project_id` filtrable, `scout:sync-index-settings` n'avait jamais été
 *     exécuté, et Meilisearch refusait chaque requête avec
 *     « Attribute `project_id` is not filterable ».
 *
 * La troisième est la plus traîtresse : l'index paraît sain, il contient bien
 * les documents attendus, et pourtant aucune recherche ne passe. Il n'existait
 * aucun moyen de la voir depuis l'extérieur du serveur.
 *
 * La sonde compare donc, pour chaque index, ce que le moteur contient à ce que
 * la base contient, **et** ses réglages effectifs à ceux déclarés dans
 * `config/scout.php`. Elle rend chaque cause distincte et nommée, avec la
 * commande qui la corrige.
 */
class SearchHealthController extends Controller
{
    /** Les modèles indexés d'Arche. */
    private const MODELS = [
        Card::class => 'Tâches',
        DocPage::class => 'Documentation',
        Decision::class => 'Décisions',
        VaultEntry::class => 'Coffre',
        ProjectFile::class => 'Fichiers',
    ];

    /**
     * Répare ce que la sonde signale.
     *
     * ## Pourquoi un point d'entrée plutôt qu'une commande
     *
     * Le diagnostic est exact depuis des jours — « réglages désynchronisés,
     * lancer `scout:sync-index-settings` » — et la recherche reste cassée,
     * parce que l'appliquer suppose un accès SSH au serveur et le bon nom de
     * conteneur. Un diagnostic qu'on ne peut pas suivre d'un geste ne vaut pas
     * beaucoup mieux qu'une panne muette.
     *
     * Les deux commandes sont figées dans le code : rien de ce que l'appelant
     * envoie n'atteint le shell. Réservé aux administrateurs.
     */
    public function repair(): JsonResponse
    {
        if (config('scout.driver') !== 'meilisearch') {
            return response()->json([
                'message' => 'Scout ne tourne pas sur Meilisearch : rien à '
                    .'synchroniser.',
            ], 422);
        }

        // Les réglages d'abord, en direct : ce ne sont que quelques appels au
        // moteur, et sans eux un index repeuplé resterait inutilisable.
        try {
            Artisan::call('scout:sync-index-settings');
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Synchronisation des réglages refusée : '
                    .$e->getMessage(),
            ], 502);
        }

        // Puis les imports, en file : un index absent doit être repeuplé, et
        // parcourir une table entière ne tient pas dans une requête HTTP.
        $aReindexer = [];

        foreach (self::MODELS as $class => $label) {
            /** @var Model $model */
            $model = new $class;

            try {
                $client = new Client(
                    config('scout.meilisearch.host'),
                    config('scout.meilisearch.key'),
                );
                $stats = $client->stats();
                $uid = config('scout.prefix').$model->searchableAs();

                // Absent, ou vide alors que la base ne l'est pas : les deux
                // demandent un import complet.
                $documents = $stats['indexes'][$uid]['numberOfDocuments'] ?? null;
                $lignes = DB::table($model->getTable())->count();

                if ($documents === null || ($documents === 0 && $lignes > 0)) {
                    ReindexModel::dispatch($class);
                    $aReindexer[] = $label;
                }
            } catch (Throwable) {
                // Un modèle qu'on n'arrive pas à jauger ne doit pas empêcher
                // les autres d'être réparés.
                continue;
            }
        }

        return response()->json([
            'settings_synced' => true,
            'reindexing' => $aReindexer,
            'message' => $aReindexer === []
                ? 'Réglages synchronisés. Aucun index à repeupler.'
                : 'Réglages synchronisés. Réindexation lancée en arrière-plan : '
                    .implode(', ', $aReindexer).'.',
        ]);
    }

    public function show(): JsonResponse
    {
        $driver = config('scout.driver');

        // Sans Meilisearch il n'y a pas d'index à comparer : Scout retombe sur
        // `collection`, qui interroge la base directement. Ce n'est pas une
        // panne, et le dire évite de chercher un moteur qui n'existe pas.
        if ($driver !== 'meilisearch') {
            return response()->json([
                'engine' => $driver,
                'reachable' => true,
                'status' => 'ok',
                'indexes' => [],
                'hint' => "Scout tourne en mode « {$driver} » : la recherche "
                    .'interroge la base, sans index à synchroniser.',
            ]);
        }

        try {
            $client = new Client(
                config('scout.meilisearch.host'),
                config('scout.meilisearch.key'),
            );
            $stats = $client->stats();
        } catch (Throwable $e) {
            return response()->json([
                'engine' => 'meilisearch',
                'reachable' => false,
                'status' => 'down',
                'indexes' => [],
                'error' => $e->getMessage(),
                'hint' => "Le moteur ne répond pas à l'adresse "
                    .config('scout.meilisearch.host').'. Vérifier que le '
                    .'conteneur Meilisearch tourne.',
            ]);
        }

        $rows = $this->rowCounts();
        $indexes = [];

        foreach (self::MODELS as $class => $label) {
            $indexes[] = $this->inspect($client, $stats, $rows, $class, $label);
        }

        return response()->json([
            'engine' => 'meilisearch',
            'reachable' => true,
            'status' => $this->worst($indexes),
            'indexes' => $indexes,
            'hint' => $this->hint($indexes),
        ]);
    }

    /**
     * @param  array<string, mixed>  $stats
     * @param  array<string, int>  $rows
     * @param  class-string<Model>  $class
     * @return array<string, mixed>
     */
    private function inspect(
        Client $client,
        array $stats,
        array $rows,
        string $class,
        string $label,
    ): array {
        /** @var Model $model */
        $model = new $class;
        $uid = config('scout.prefix').$model->searchableAs();
        $expected = config("scout.meilisearch.index-settings.{$model->searchableAs()}.filterableAttributes", []);

        $documents = $stats['indexes'][$uid]['numberOfDocuments'] ?? null;
        $inBase = $rows[$model->getTable()] ?? null;

        if ($documents === null) {
            return [
                'index' => $uid,
                'label' => $label,
                'documents' => null,
                'rows' => $inBase,
                'missing_filters' => $expected,
                'status' => 'missing',
            ];
        }

        // Réglages effectifs du moteur : c'est ici que se joue la panne
        // silencieuse. Un index bien peuplé mais mal réglé rejette toutes les
        // requêtes qui filtrent par projet — c'est-à-dire toutes les nôtres.
        try {
            $settings = $client->index($uid)->getSettings();
            $filterable = $settings['filterableAttributes'] ?? [];
        } catch (Throwable) {
            $filterable = [];
        }

        $missing = array_values(array_diff($expected, $filterable));

        return [
            'index' => $uid,
            'label' => $label,
            'documents' => $documents,
            'rows' => $inBase,
            'missing_filters' => $missing,
            'status' => $this->verdict($documents, $inBase, $missing),
        ];
    }

    /**
     * `misconfigured` prime sur `stale` : un index périmé rend des résultats
     * incomplets, un index mal réglé n'en rend aucun.
     */
    private function verdict(int $documents, ?int $rows, array $missing): string
    {
        if ($missing !== []) {
            return 'misconfigured';
        }
        if ($rows !== null && $documents !== $rows) {
            return 'stale';
        }

        return 'ok';
    }

    /** @param  array<int, array<string, mixed>>  $indexes */
    private function worst(array $indexes): string
    {
        $states = array_column($indexes, 'status');

        foreach (['missing', 'misconfigured', 'stale'] as $bad) {
            if (in_array($bad, $states, true)) {
                return $bad === 'stale' ? 'warn' : 'down';
            }
        }

        return 'ok';
    }

    /**
     * La commande exacte à lancer, plutôt qu'un diagnostic à interpréter.
     *
     * @param  array<int, array<string, mixed>>  $indexes
     */
    private function hint(array $indexes): ?string
    {
        $states = array_column($indexes, 'status');

        if (in_array('misconfigured', $states, true)) {
            return 'Réglages du moteur désynchronisés du dépôt : '
                .'`php artisan scout:sync-index-settings`.';
        }
        if (in_array('missing', $states, true)) {
            return 'Index absent — jamais peuplé : `php artisan scout:import` '
                .'sur les modèles concernés.';
        }
        if (in_array('stale', $states, true)) {
            return 'Le moteur et la base ne comptent pas la même chose : '
                .'`php artisan scout:import` pour réaligner.';
        }

        return null;
    }

    /**
     * Compte les lignes des cinq tables en **un seul** aller-retour.
     *
     * Cinq `count()` séparés coûteraient cinq allers-retours vers Francfort
     * pour une simple sonde. Les sous-requêtes scalaires font le même travail
     * en un.
     *
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        $tables = [];
        foreach (array_keys(self::MODELS) as $class) {
            /** @var Model $model */
            $model = new $class;
            $tables[] = $model->getTable();
        }
        $tables = array_unique($tables);

        $select = implode(', ', array_map(
            fn (string $t) => "(select count(*) from \"{$t}\") as \"{$t}\"",
            $tables,
        ));

        try {
            $row = (array) DB::selectOne("select {$select}");

            return array_map('intval', $row);
        } catch (Throwable) {
            // La sonde ne doit jamais tomber : sans les comptes, on rapporte
            // quand même l'état du moteur.
            return [];
        }
    }
}
