<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Serveurs de relais
    |--------------------------------------------------------------------------
    |
    | Le relais n'entre en jeu que lorsque deux téléphones ne peuvent pas se
    | joindre directement — un réseau sur quatre. Sur les autres, l'appel passe
    | en pair-à-pair et ces serveurs ne voient rien.
    |
    | ## Plusieurs relais, du plus proche au plus lointain
    |
    | `TURN_HOSTS` accepte une liste séparée par des virgules, et l'ordre
    | compte : le premier est proposé en tête, et c'est celui que la
    | négociation retient quand plusieurs aboutissent.
    |
    | L'intérêt est géographique. Deux téléphones à Libreville dont la
    | connexion directe échoue voient aujourd'hui leur voix passer par
    | Francfort — 70 ms dans chaque sens, soit 140 ms de trajet, là où un
    | relais local en coûterait vingt. C'est la différence entre une
    | conversation transparente et une où l'on se coupe la parole.
    |
    | Un relais proche ne remplace pas le lointain, il le précède : si le local
    | est injoignable, l'appel bascule sur l'autre au lieu d'échouer.
    |
    |     TURN_HOSTS=turn.paynala.ga,arche.paynala.com
    |
    | `TURN_HOST` reste accepté pour un relais unique.
    |
    */

    'turn_hosts' => env('TURN_HOSTS', env('TURN_HOST', '')),

    /*
    |--------------------------------------------------------------------------
    | Secret de signature
    |--------------------------------------------------------------------------
    |
    | Identique à `static-auth-secret` dans la configuration de coturn. Il ne
    | quitte jamais le serveur : l'API s'en sert pour signer des identifiants
    | temporaires, et c'est ce couple-là que l'application reçoit.
    |
    | Partagé par tous les relais : ils sont tous à nous, et leur donner des
    | secrets distincts obligerait à en tenir la correspondance sans rien
    | protéger de plus.
    |
    | Vide, les appels fonctionnent quand même — simplement pas partout. Le
    | monitoring le signale plutôt que de laisser deviner.
    |
    */

    'turn_secret' => env('TURN_SECRET'),
];
