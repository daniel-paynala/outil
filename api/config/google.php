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
    | Notifications Gmail
    |--------------------------------------------------------------------------
    |
    | `topic` est le sujet Pub/Sub que Gmail alimente, au format complet
    | `projects/<projet>/topics/<sujet>`.
    |
    | `pubsub_token` est un secret partagé placé en paramètre d'URL de
    | l'abonnement push. Le point d'entrée de réception est nécessairement hors
    | authentification — c'est Google qui appelle, pas un utilisateur — et ce
    | jeton est ce qui distingue un appel légitime de n'importe qui ayant
    | trouvé l'adresse.
    |
    */
    'topic' => env('GOOGLE_PUBSUB_TOPIC'),
    'pubsub_token' => env('GOOGLE_PUBSUB_TOKEN'),

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
