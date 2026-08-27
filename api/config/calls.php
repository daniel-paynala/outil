<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Serveur de relais
    |--------------------------------------------------------------------------
    |
    | Le relais n'entre en jeu que lorsque deux téléphones ne peuvent pas se
    | joindre directement — un réseau sur quatre. Sur les autres, l'appel passe
    | en pair-à-pair et ce serveur ne voit rien.
    |
    | `turn_secret` doit être identique à `static-auth-secret` dans
    | `deploy/coturn.conf`. Il ne quitte jamais le serveur : l'API s'en sert
    | pour signer des identifiants temporaires, et c'est ce couple-là que
    | l'application reçoit.
    |
    | Vides, les appels fonctionnent quand même — simplement pas partout. Le
    | monitoring le signale plutôt que de laisser deviner.
    |
    */
    'turn_host' => env('TURN_HOST'),
    'turn_secret' => env('TURN_SECRET'),
];
