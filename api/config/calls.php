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
    | L'intérêt est géographique. Mesuré depuis Gabon Telecom à Libreville, le
    | 29/08/2026, aller-retour TCP :
    |
    |     Paris      136 ms        Le Cap     173 ms
    |     Francfort  164 ms        Virginie   187 ms
    |
    | Deux téléphones à Libreville dont la connexion directe échoue voient leur
    | voix parcourir Libreville → Europe → Libreville, soit environ 80 ms dans
    | chaque sens. Un relais local en coûterait une dizaine : le premier saut
    | hors de la box est à 6 ms.
    |
    | C'est la différence entre une conversation transparente et une où l'on se
    | coupe la parole.
    |
    | Déménager le relais actuel ne servirait à rien — aucune région testée ne
    | bat l'Europe depuis Libreville. Il faut **ajouter** un relais réellement
    | local, pas déplacer celui-là.
    |
    | ## Le nom doit pointer sur le VPS, pas sur Cloudflare
    |
    | Cloudflare ne relaie ni l'UDP 3478 ni le TCP 3478/5349. Un nom proxifié
    | rend donc le relais injoignable, et la panne est silencieuse : les
    | identifiants sont bien signés, l'application les reçoit, la négociation
    | se rabat sur le direct, et les appels échouent partout où le direct ne
    | passe pas.
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
