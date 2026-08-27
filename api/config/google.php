<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Identifiants OAuth
    |--------------------------------------------------------------------------
    |
    | Ceux du client « Web » de la console Google Cloud — c'est lui qui échange
    | le code d'autorisation contre un jeton de rafraîchissement, y compris
    | quand le code vient d'une application Android ou iOS. Les identifiants
    | mobiles servent uniquement à l'authentification sur l'appareil et n'ont
    | pas de secret.
    |
    */
    'client_id' => env('GOOGLE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CLIENT_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Domaine Workspace
    |--------------------------------------------------------------------------
    |
    | Refuse toute adresse extérieure à l'organisation. Sans ce garde-fou,
    | quelqu'un pourrait rattacher une boîte personnelle à son compte Arche et
    | faire transiter du courrier privé par la surveillance de l'entreprise.
    |
    */
    'workspace_domain' => env('GOOGLE_WORKSPACE_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Relève des boîtes
    |--------------------------------------------------------------------------
    |
    | Aucune configuration : la relève est planifiée dans `routes/console.php`
    | et n'a besoin que du jeton de rafraîchissement de chaque compte.
    |
    | La voie poussée de Gmail — `users.watch()` et un sujet Pub/Sub — a été
    | abandonnée : elle exige d'accorder un rôle IAM à
    | `gmail-api-push@system.gserviceaccount.com`, et la règle « Partage
    | restreint au domaine » de l'organisation Paynala refuse tout principal
    | hors du domaine. Voir MAIL.md.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Portées demandées
    |--------------------------------------------------------------------------
    |
    | `gmail.modify` couvre la lecture, l'envoi, l'archivage et les libellés
    | sans donner la suppression définitive : `gmail.modify` déplace vers la
    | corbeille, là où `https://mail.google.com/` permettrait d'effacer sans
    | retour. On s'en tient au moindre privilège qui fasse le travail.
    |
    | Ces portées sont « restreintes » chez Google : une application publique
    | devrait passer une vérification annuelle avec audit de sécurité. Arche y
    | échappe parce que son écran de consentement est réglé sur « Interne »,
    | ce qui n'est possible que pour une organisation Workspace et limite
    | l'accès aux comptes du domaine.
    |
    */
    'scopes' => [
        'https://www.googleapis.com/auth/gmail.modify',
        'https://www.googleapis.com/auth/userinfo.email',
    ],
];
