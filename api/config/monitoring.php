<?php

return [

    /*
    |---------------------------------------------------------------------
    | Le fuseau des journées
    |---------------------------------------------------------------------
    |
    | Une fenêtre calendaire compte « depuis minuit ». Reste à savoir minuit
    | où : Arche stocke tout en UTC, mais l'équipe et les bases surveillées
    | sont au Gabon. Minuit UTC tombe à 1 h du matin à Libreville — une sonde
    | calendaire réglée sur UTC redémarrerait son comptage en pleine nuit
    | locale, au milieu de la période la plus creuse, et couperait en deux les
    | incidents nocturnes.
    |
    | Ce fuseau ne sert qu'à trouver le début de journée. Tout le reste — les
    | horodatages stockés, les fenêtres glissantes — demeure en UTC.
    |
    */

    'timezone' => env('MONITORING_TIMEZONE', 'Africa/Libreville'),

];
