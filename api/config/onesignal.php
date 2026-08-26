<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OneSignal
    |--------------------------------------------------------------------------
    |
    | Notifications push. Vides tant que le compte n'est pas créé : l'envoi
    | est alors ignoré sans erreur, plutôt que de faire échouer la requête qui
    | l'a déclenché. Une messagerie doit continuer de fonctionner même si le
    | fournisseur de push est absent ou en panne.
    |
    | La clé REST est un secret serveur — elle permet d'envoyer à n'importe
    | quel utilisateur de l'app. Elle n'a rien à faire côté client.
    |
    */

    'app_id' => env('ONESIGNAL_APP_ID'),

    'rest_key' => env('ONESIGNAL_REST_KEY'),

    'endpoint' => env('ONESIGNAL_ENDPOINT', 'https://api.onesignal.com/notifications'),

    /*
    | Délai d'appel. Court volontairement : l'envoi tourne dans la file
    | d'attente, mais un fournisseur qui ne répond pas ne doit pas immobiliser
    | un worker pendant une minute.
    */
    'timeout' => 8,

];
