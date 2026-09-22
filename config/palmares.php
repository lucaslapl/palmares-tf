<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Répertoire des données
    |--------------------------------------------------------------------------
    |
    | Les JSON générés (leaderboards, index de recherche) vivent sous
    | storage/app/palmares, résolu via palmares_data_path().
    |
    */
    'data_dir' => 'app/palmares',

    /*
    |--------------------------------------------------------------------------
    | Client API ETF2L v2
    |--------------------------------------------------------------------------
    |
    | L'API est publique mais limitée (~60 requêtes/minute). On respecte un
    | délai minimum entre deux appels et on cache les réponses en base
    | (table etf2l_api_cache).
    |
    */
    'etf2l' => [
        'base_url' => 'https://api-v2.etf2l.org',
        'user_agent' => 'palmares.tf/1.0',
        'request_delay_s' => 1.1,
        'http_timeout_s' => 20,
        'max_attempts' => 5,
        'backoffs' => [0, 2, 10, 30, 60],
        'results_per_page' => 50,
        'cache_ttl' => [
            // Résultats de joueurs (1 semaine).
            'results' => 7 * 86400,
            // Tables de classement final (30 jours : immuables une fois la saison terminée).
            'tables' => 30 * 86400,
            // Profils/équipes (7 jours).
            'profiles' => 7 * 86400,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Catégories de compétitions
    |--------------------------------------------------------------------------
    |
    | Les "saisons" sont les compétitions de ligue (6v6 / Highlander). Les
    | Nations Cup sont traitées à part : pas de tables avec "ach", les podiums
    | sont inférés depuis les finales.
    |
    */
    'categories' => [
        'seasons' => ['6v6 Season', 'Highlander Season'],
        'nations_cup' => ["Nations' Cup"],
    ],

    /*
    |--------------------------------------------------------------------------
    | Modes de jeu
    |--------------------------------------------------------------------------
    |
    | Codes internes : "6s" (6v6) et "9v9" (Highlander). La liste des formats
    | exposée dans l'UI et dans les JSON.
    |
    */
    'mode_map' => [
        'Highlander' => '9v9',
        '6v6' => '6s',
    ],
    'nations_mode_map' => [
        'National Highlander Team' => '9v9',
        'National 6v6 Team' => '6s',
    ],
    'formats' => [
        '6s' => ['label' => '6v6', 'slug' => '6v6'],
        '9v9' => ['label' => '9v9', 'slug' => '9v9'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pondération du classement
    |--------------------------------------------------------------------------
    |
    | Or = 3, argent = 2, bronze = 1. Le classement est trié par points, puis
    | par nombre d'or, d'argent, de bronze, puis nom.
    |
    */
    'weights' => [
        'gold' => 3,
        'silver' => 2,
        'bronze' => 1,
    ],
    'medals' => [
        1 => 'gold',
        2 => 'silver',
        3 => 'bronze',
    ],

    /*
    |--------------------------------------------------------------------------
    | Staleness / fraîcheur
    |--------------------------------------------------------------------------
    |
    | Après ce délai, le palmarès d'un joueur est recalculé à la prochaine
    | visite (profil) ou au prochain passage de la tâche planifiée.
    |
    */
    'staleness' => [
        'palmares_compute_s' => 7 * 86400,
    ],
];
