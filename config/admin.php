<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Panel admin
    |--------------------------------------------------------------------------
    |
    | Le panel de monitoring est protégé par deux couches : un lien secret
    | (ADMIN_ACCESS_TOKEN, comparé via hash_equals) qui ouvre la porte, puis un
    | login classique (ADMIN_USERNAME + ADMIN_PASSWORD_HASH, hash bcrypt).
    | Aucun secret n'est codé en dur : tout provient de l'environnement.
    |
    */
    'access_token' => env('ADMIN_ACCESS_TOKEN', ''),
    'username' => env('ADMIN_USERNAME', 'admin'),
    'password_hash' => env('ADMIN_PASSWORD_HASH', ''),

    /*
    |--------------------------------------------------------------------------
    | Rétention de l'historique d'exécution
    |--------------------------------------------------------------------------
    |
    | Les runs des commandes app:* (table scheduled_command_runs) sont purgés
    | au-delà de cette durée.
    |
    */
    'runs_retention_days' => 30,
];
