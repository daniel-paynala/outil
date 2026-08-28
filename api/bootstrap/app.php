<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureSupabaseAuth;
use App\Http\Middleware\ResolveSupabaseAuth;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        /*
         * Derrière le reverse proxy qui termine le TLS.
         *
         * Sans cela, Laravel ne voit que la connexion HTTP interne entre nginx
         * et PHP : `$request->isSecure()` est faux, et toute URL absolue qu'il
         * génère — lien d'invitation, redirection — sort en `http://`. Le lien
         * fonctionne, ce qui rend le défaut invisible : il déclasse simplement
         * la connexion sans que personne ne le remarque.
         *
         * Il perd aussi l'adresse réelle de l'appelant, remplacée par celle du
         * proxy : toute limitation ou journalisation par IP compterait tout le
         * monde comme un seul visiteur.
         *
         * `*` est correct **parce que** l'application n'est joignable qu'à
         * travers ce proxy — le port du conteneur est lié à la boucle locale.
         * Exposer le conteneur directement rendrait ce réglage dangereux :
         * n'importe qui pourrait alors se déclarer une autre adresse.
         */
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'supabase.auth' => EnsureSupabaseAuth::class,
            'supabase.maybe' => ResolveSupabaseAuth::class,
            'admin' => EnsureAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
