<?php

namespace App\Modules\Monitoring\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Intégrité de l'installation serveur.
 *
 * Les autres sondes répondent à « ce service tourne-t-il ? ». Celle-ci répond
 * à « cette installation est-elle celle qu'on croit ? » — question distincte,
 * et longtemps sans réponse : le serveur d'Arche est passé de systemd à Docker
 * sans que rien ne le consigne, des tables ont été créées à la main hors de
 * toute migration, et des modules entiers tournent sans figurer au dépôt.
 * Chacun de ces écarts a coûté un diagnostic faux.
 *
 * On rapporte donc ce qui *est*, pas ce que le dépôt prétend : versions
 * réelles, réglages effectifs, tables réellement présentes.
 */
class IntegrityController extends Controller
{
    /**
     * Tables qu'Arche doit trouver pour fonctionner.
     *
     * La liste est tenue à la main plutôt que déduite des migrations,
     * justement parce que plusieurs de ces tables n'en ont pas : c'est l'écart
     * entre les deux qu'il s'agit de rendre visible.
     */
    private const REQUIRED_TABLES = [
        'users', 'projects', 'project_members', 'columns', 'cards',
        'labels', 'card_labels', 'card_dependencies', 'card_comments',
        'doc_pages', 'doc_revisions', 'decisions', 'vault_entries',
        'time_entries', 'activity_logs', 'project_files',
        'github_repos', 'github_commits', 'releases', 'roadmap_items',
        'conversations', 'conversation_members', 'messages',
        'message_attachments', 'notifications',
    ];

    public function show(): JsonResponse
    {
        $checks = [
            $this->runtime(),
            $this->configuration(),
            $this->schema(),
            $this->storage(),
            $this->services(),
        ];

        return response()->json([
            'checks' => $checks,
            'status' => $this->worst($checks),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /** Versions effectivement chargées — pas celles du `composer.json`. */
    private function runtime(): array
    {
        return [
            'key' => 'runtime',
            'label' => 'Exécution',
            'status' => version_compare(PHP_VERSION, '8.2', '>=') ? 'ok' : 'warn',
            'facts' => [
                'PHP' => PHP_VERSION,
                'Laravel' => app()->version(),
                'Environnement' => app()->environment(),
                'Fuseau' => config('app.timezone'),
            ],
        ];
    }

    /**
     * Réglages dont une valeur erronée ne se voit pas à l'usage.
     *
     * `APP_DEBUG` à vrai en production est le cas d'école : tout fonctionne, et
     * chaque erreur expose la trace d'appel, les requêtes et une partie de la
     * configuration à qui la déclenche.
     */
    private function configuration(): array
    {
        $debug = (bool) config('app.debug');
        $production = app()->environment('production');
        $problems = [];

        if ($debug && $production) {
            $problems[] = 'APP_DEBUG est actif en production : chaque erreur '
                .'expose la trace et la configuration.';
        }
        if (empty(config('app.key'))) {
            $problems[] = 'APP_KEY est vide : tout ce qui est chiffré est illisible.';
        }
        if (config('cache.default') === 'database') {
            $problems[] = 'Le cache passe par la base distante : le gain de '
                .'latence de l\'authentification est annulé (CACHE_STORE=redis).';
        }

        return [
            'key' => 'configuration',
            'label' => 'Configuration',
            'status' => $problems === [] ? 'ok' : ($debug && $production ? 'down' : 'warn'),
            'facts' => [
                'Débogage' => $debug ? 'actif' : 'inactif',
                'Cache' => config('cache.default'),
                'File' => config('queue.default'),
                'Recherche' => config('scout.driver'),
                'Sessions' => config('session.driver'),
            ],
            'problems' => $problems,
        ];
    }

    /**
     * Écart entre le schéma attendu, le schéma présent, et les migrations.
     *
     * Le troisième terme est celui qui manquait : une table peut exister en
     * base sans qu'aucune migration ne la décrive — c'est le cas de toute la
     * messagerie, créée en SQL direct. Reconstruire le serveur à partir du
     * dépôt donnerait alors une base incomplète, et personne ne le saurait
     * avant l'incident.
     */
    private function schema(): array
    {
        $problems = [];
        $missing = [];

        try {
            foreach (self::REQUIRED_TABLES as $table) {
                if (! Schema::hasTable($table)) {
                    $missing[] = $table;
                }
            }
        } catch (Throwable $e) {
            return [
                'key' => 'schema',
                'label' => 'Schéma',
                'status' => 'down',
                'facts' => ['Erreur' => $e->getMessage()],
            ];
        }

        if ($missing !== []) {
            $problems[] = 'Tables absentes : '.implode(', ', $missing);
        }

        $described = $this->tablesDescribedByMigrations();
        $undescribed = array_values(array_diff(
            array_diff(self::REQUIRED_TABLES, $missing),
            $described,
        ));

        if ($undescribed !== []) {
            $problems[] = count($undescribed).' table(s) existent en base sans '
                .'migration qui les décrive — une reconstruction du serveur à '
                .'partir du dépôt donnerait un schéma incomplet : '
                .implode(', ', $undescribed);
        }

        return [
            'key' => 'schema',
            'label' => 'Schéma',
            'status' => $missing !== [] ? 'down' : ($undescribed !== [] ? 'warn' : 'ok'),
            'facts' => [
                'Tables attendues' => (string) count(self::REQUIRED_TABLES),
                'Tables absentes' => (string) count($missing),
                'Sans migration' => (string) count($undescribed),
                'Migrations en attente' => $this->pendingMigrations(),
            ],
            'problems' => $problems,
        ];
    }

    /**
     * Tables qu'une migration du dépôt prétend créer.
     *
     * Lecture textuelle des fichiers plutôt qu'exécution : on cherche ce que le
     * dépôt *décrit*, indépendamment de ce qui a déjà tourné.
     *
     * @return array<int, string>
     */
    private function tablesDescribedByMigrations(): array
    {
        $tables = [];

        try {
            foreach (File::files(database_path('migrations')) as $file) {
                preg_match_all(
                    "/Schema::create\(\s*'([a-z_]+)'/",
                    File::get($file->getPathname()),
                    $matches,
                );
                $tables = [...$tables, ...$matches[1]];
            }
        } catch (Throwable) {
            return [];
        }

        return array_unique($tables);
    }

    private function pendingMigrations(): string
    {
        try {
            $ran = DB::table('migrations')->count();
            $files = count(File::files(database_path('migrations')));

            return $files > $ran ? (string) ($files - $ran) : '0';
        } catch (Throwable) {
            return 'inconnu';
        }
    }

    /** Écriture réelle, pas seulement les permissions déclarées. */
    private function storage(): array
    {
        $problems = [];
        $writable = [];

        foreach (['logs' => storage_path('logs'), 'cache' => storage_path('framework/cache')] as $name => $path) {
            $ok = is_dir($path) && is_writable($path);
            $writable[$name] = $ok ? 'accessible' : 'NON accessible';
            if (! $ok) {
                $problems[] = "storage/{$name} n'est pas accessible en écriture : "
                    .'les erreurs ne seront pas journalisées.';
            }
        }

        $free = @disk_free_space(base_path());
        $total = @disk_total_space(base_path());
        if ($free && $total && $free / $total < 0.10) {
            $problems[] = sprintf(
                'Disque à %d %% : en dessous de 10 %% libres, les écritures '
                .'commencent à échouer.',
                round(100 * (1 - $free / $total)),
            );
        }

        return [
            'key' => 'storage',
            'label' => 'Stockage',
            'status' => $problems === [] ? 'ok' : 'warn',
            'facts' => [
                ...$writable,
                'Disque libre' => $free ? $this->humanBytes($free) : 'inconnu',
            ],
            'problems' => $problems,
        ];
    }

    /** Adresses effectivement configurées, pour comparer à ce qu'on croit. */
    private function services(): array
    {
        return [
            'key' => 'services',
            'label' => 'Services',
            'status' => 'ok',
            'facts' => [
                'Base' => config('database.connections.'.config('database.default').'.host')
                    .':'.config('database.connections.'.config('database.default').'.port'),
                'Recherche' => (string) config('scout.meilisearch.host'),
                'Redis' => config('database.redis.default.host')
                    .':'.config('database.redis.default.port'),
                'Push' => config('onesignal.app_id') ? 'configuré' : 'absent',
            ],
        ];
    }

    /** @param  array<int, array<string, mixed>>  $checks */
    private function worst(array $checks): string
    {
        $states = array_column($checks, 'status');

        if (in_array('down', $states, true)) {
            return 'down';
        }

        return in_array('warn', $states, true) ? 'warn' : 'ok';
    }

    private function humanBytes(float $bytes): string
    {
        foreach (['o', 'ko', 'Mo', 'Go', 'To'] as $unit) {
            if ($bytes < 1024) {
                return round($bytes, 1)." {$unit}";
            }
            $bytes /= 1024;
        }

        return round($bytes, 1).' Po';
    }
}
