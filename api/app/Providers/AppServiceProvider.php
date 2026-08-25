<?php

namespace App\Providers;

use App\Database\SupabasePostgresConnection;
use Illuminate\Database\Connection;
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
        //
    }
}
