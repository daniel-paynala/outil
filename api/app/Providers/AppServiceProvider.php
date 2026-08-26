<?php

namespace App\Providers;

use App\Database\SupabasePostgresConnection;
use Illuminate\Database\Connection;
use App\Modules\Monitoring\Http\Controllers\QueueHealthController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Corrige la liaison des booléens à travers le pooler Supabase.
        // Doit être enregistré avant toute connexion — voir la classe pour le
        // détail du problème.
        Connection::resolverFor(
            'pgsql',
            fn ($connection, $database, $prefix, $config) => new SupabasePostgresConnection(
                $connection, $database, $prefix, $config,
            ),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Battement de cœur de la file.
        //
        // Laravel ne conserve aucune trace d'un worker vivant : un job qui
        // dort faute de consommateur est indiscernable d'une file vide. En
        // datant chaque job terminé, la sonde de monitoring peut répondre à
        // « quelqu'un consomme-t-il ? » plutôt qu'au seul « y a-t-il quelque
        // chose à faire ? ».
        //
        // Écrit dans le cache partagé (Redis en production), donc lisible par
        // le processus web alors que l'écriture vient du worker.
        Queue::after(function () {
            Cache::put(
                QueueHealthController::LAST_PROCESSED_KEY,
                now()->timestamp,
                now()->addDay(),
            );
        });
    }
}
