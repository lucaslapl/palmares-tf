# palmares.tf

Classement des joueurs TF2 compétitifs par leurs « récompenses de saison » ETF2L : les
médailles d'équipe **or / argent / bronze** remportées en **6v6** et **Highlander**,
toutes divisions confondues.

Production : `palmares.tf`. Site autonome Laravel 13 (PHP 8.3+), SQLite en local /
MySQL en production.

## Qu'est-ce que ça fait

- **Leaderboards** (global, 6v6, 9v9) triés par points — pondération or = 3, argent = 2,
  bronze = 1, puis nombre d'or / d'argent / de bronze.
- **Profil de chaque joueur** : ses saisons (compétition, équipe, division, médaille ou
  round de playoffs atteint), même sans récompense. Les profils sont **calculés à la
  demande** lors de la première visite (philosophie « self-healing » : caches en JSON
  régénérés depuis la base).
- **Recherche** de joueurs par nom (index JSON pré-généré).
- **Pages saisons** : tables de classement final par division, podiums en tête.

## Pipeline de données

Sources : API ETF2L v2 (`api-v2.etf2l.org`), publique, limitée (~60 req/min, throttling
1,1 s imposé), réponses cachées en base (`etf2l_api_cache`) avec TTL par endpoint.

```
populated par              consommé par
─────────────────────      ──────────────
app:sync-seasons           saisons (compétitions de ligue)
app:sync-tables            tables de classement final (ach 1/2/3) → saisons + équipes
app:harvest-players        vivier de joueurs (rosters + transferts des équipes classées)
app:compute-palmares       palmarès joueur par joueur (synchronisé)
app:generate-json          leaderboards + index de recherche → storage/app/palmares/*.json

app:sync-all               tout le pipeline d'un coup (--force pour tout re-traiter)
```

Le moteur de calcul (`ComputePalmaresService`) croise les résultats de matches d'un
joueur avec les tables de classement (`ach`) pour en déduire les podiums, détecte les
rounds de playoffs significatifs (Grand Final, finales de bracket, demi-finales…) et
déduplique par saison logique (saison régulière + playoffs séparés). Les Nations Cup ne
comptent qu'à partir du podium, déduit des finales.

Les JSON publics (`storage/app/palmares/`) sont écrits de façon atomique sous verrou
(`flock`), refroidis/se régénèrent eux-mêmes à la lecture s'ils sont absents. En production,
MySQL et planification via `routes/console.php` (toutes les tâches en `withoutOverlapping`).

## Environnement local

Docker est obligatoire — ne lancez jamais `composer test`, `php artisan`, `pint` ou `npm`
directement sur l'hôte.

```bash
# Lancer l'app (http://localhost:8000)
docker compose up -d app

# Logs du serveur
docker compose logs -f app

# Tests
docker compose run --rm test                  # = composer test

# Shell
docker compose run --rm test sh

# Pint (style)
docker compose run --rm test vendor/bin/pint --test

# Assets
docker compose run --rm test npm run build
```

Première installation : `composer install && php artisan key:generate && touch database/database.sqlite && php artisan migrate`.

## Architecture

- **Pas d'Eloquent pour la donnée métier** : accès via des repositories (`app/Models/*Repository`)
  sur le query builder. Seul le squelette Laravel (`User`) est en Eloquent.
- **Tests** : PHPUnit avec attributes « style Pest » (`#[Test]`), suites `tests/Unit` et
  `tests/Feature`, fixtures JSON capturées dans `tests/Fixtures/etf2l/`, `Http::fake()`
  pour ne jamais toucher le réseau.
- **Frontend vanilla** : `public/_css/app.css`, `public/_js/search.js`, cache-busting via
  `palmares_asset()`. Vite/Tailwind ne compile que `resources/css/app.css`.
- **Concurrence** : `flock()` (calculs, écriture des JSON) et `withoutOverlapping()`.

## Tests

```bash
docker compose run --rm test
```

## Licence

Projet privé — aucune réutilisation sans autorisation.