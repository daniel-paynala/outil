<?php

namespace App\Modules\Monitoring\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Adr\Models\Decision;
use App\Modules\Docs\Models\DocPage;
use App\Modules\Files\Models\ProjectFile;
use App\Modules\Monitoring\Jobs\RepairSearchIndexes;
use App\Modules\Tasks\Models\Card;
use App\Modules\Vault\Models\VaultEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        // Les modèles dont l'index est absent, ou vide alors que la table ne
        // l'est pas : eux seuls demandent un import complet. Réindexer ce qui
        // n'en a pas besoin coûterait des minutes pour rien.
        $aReindexer = [];

        try {
            $client = new Client(
                config('scout.meilisearch.host'),
                config('scout.meilisearch.key'),
            );
            $stats = $client->stats();

            foreach (array_keys(self::MODELS) as $class) {
                /** @var Model $model */
                $model = new $class;
                $uid = config('scout.prefix').$model->searchableAs();
                $documents = $stats['indexes'][$uid]['numberOfDocuments'] ?? null;

                if ($documents === null || $documents === 0) {
                    $aReindexer[] = $class;
                }
            }
        } catch (Throwable $e) {
            return response()->json([
                'message' => "Le moteur ne répond pas : {$e->getMessage()}",
            ], 502);
        }

        RepairSearchIndexes::dispatch($aReindexer);

        return response()->json([
            'queued' => true,
            'reindexing' => count($aReindexer),
            'message' => $aReindexer === []
                ? 'Synchronisation lancée. Actualise dans quelques instants.'
                : 'Synchronisation et réindexation de '.count($aReindexer)
                    .' index lancées. Actualise dans une minute.',
        ], 202);
    }

    /**
     * État du moteur d'indexation.
     *
     * ## Deux réponses pour une seule route
     *
     * La route reste ouverte : c'est justement quand l'authentification tombe
     * qu'on a besoin de lire une sonde. Mais elle n'a pas à livrer la même
     * chose à tout le monde.
     *
     * Elle le faisait, et disait le contraire dans son propre commentaire :
     * « ne divulgue que des compteurs ». En réalité elle rendait le volume
     * d'activité de l'entreprise — combien de tâches, combien de documents,
     * combien de secrets au coffre — plus l'adresse interne du moteur et le
     * texte brut de ses exceptions, à qui passait sans jeton.
     *
     * Un inconnu reçoit maintenant un état : le moteur répond ou non, chaque
     * index est sain ou non. De quoi constater une panne, pas de quoi
     * renseigner qui que ce soit sur l'entreprise. L'équipe, elle, garde les
     * chiffres — ce sont eux qui permettent de réparer.
     */
    public function show(Request $request): JsonResponse
    {
        $identifie = $request->attributes->has('user');
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
            // Le message d'exception nomme l'hôte, le port, parfois l'entête
            // d'authentification refusé. Indispensable pour réparer, à ne pas
            // laisser traîner pour autant.
            return response()->json([
                'engine' => 'meilisearch',
                'reachable' => false,
                'status' => 'down',
                'indexes' => [],
                'error' => $identifie ? $e->getMessage() : null,
                'hint' => $identifie
                    ? "Le moteur ne répond pas à l'adresse "
                        .config('scout.meilisearch.host').'. Vérifier que le '
                        .'conteneur Meilisearch tourne.'
                    : "Le moteur d'indexation ne répond pas.",
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
            'indexes' => $identifie ? $indexes : $this->sansChiffres($indexes),
            // L'indication nomme la commande à lancer : utile à qui peut la
            // lancer, inventaire de nos rouages pour les autres.
            'hint' => $identifie ? $this->hint($indexes) : null,
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
    /**
     * Le même verdict, sans les volumes.
     *
     * On garde `status` : c'est ce qui permet de constater qu'un index va mal.
     * On retire `documents`, `rows` et `missing_filters`, qui disent
     * respectivement combien l'entreprise produit, combien elle stocke, et
     * comment son moteur est réglé.
     *
     * `index` part aussi : le nom technique révèle le préfixe de déploiement.
     *
     * @param  array<int, array<string, mixed>>  $indexes
     * @return array<int, array<string, mixed>>
     */
    private function sansChiffres(array $indexes): array
    {
        return array_map(
            fn (array $i) => ['label' => $i['label'], 'status' => $i['status']],
            $indexes,
        );
    }

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
