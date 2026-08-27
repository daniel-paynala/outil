<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Authentification par jeton
    |--------------------------------------------------------------------------
    |
    | Une clé `.p8` plutôt qu'un certificat, pour une raison qui compte : les
    | certificats VoIP d'Apple **expirent au bout d'un an**. Le jour venu, les
    | appels cessent d'arriver sans erreur ni avertissement — la panne muette
    | qu'Arche a déjà connue deux fois. Une clé `.p8` n'expire jamais.
    |
    | La même clé sert aux notifications ordinaires et aux pushes VoIP : seul
    | l'en-tête `apns-topic` change. Aucun certificat séparé n'est nécessaire,
    | contrairement à ce qu'exige l'authentification par certificat.
    |
    | `key_path` pointe vers le fichier `.p8`, monté dans le conteneur et **hors
    | du dépôt** : c'est une clé privée qui signe l'identité de l'éditeur.
    |
    */
    'key_path' => env('APNS_KEY_PATH', storage_path('app/apns/AuthKey.p8')),
    'key_id' => env('APNS_KEY_ID'),
    'team_id' => env('APNS_TEAM_ID'),

    /*
    |--------------------------------------------------------------------------
    | Destination
    |--------------------------------------------------------------------------
    |
    | Le sujet VoIP est l'identifiant de bundle suivi de `.voip`. Apple le
    | vérifie : un sujet qui ne correspond à aucune application déclarée est
    | refusé avec `BadTopic`.
    |
    | `production` bascule entre les deux serveurs d'Apple. Un jeton obtenu par
    | une compilation de développement n'est valable que sur `sandbox`, et
    | inversement — c'est la cause n°1 des `BadDeviceToken` lors d'une mise en
    | production.
    |
    */
    'bundle_id' => env('APNS_BUNDLE_ID', 'com.paynala.arche'),
    'production' => env('APNS_PRODUCTION', false),

    'endpoints' => [
        'production' => 'https://api.push.apple.com',
        'sandbox' => 'https://api.sandbox.push.apple.com',
    ],
];
