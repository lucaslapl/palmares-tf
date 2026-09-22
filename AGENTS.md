# AGENTS.md

## Vue d'ensemble du projet

« palmares.tf » — site Laravel 13 (PHP 8.3+) qui classe les joueurs TF2 compétitifs selon
les médailles de saison ETF2L (or/argent/bronze), toutes divisions, pondération 3/2/1.
Il vit dans son propre repo (pas de `bot/` ni `plugins/`). Tous les docblocks, commentaires
et messages de commit sont **en français** ; l'UI du site est en **anglais**.

## Environnement local

- **Docker est obligatoire.** Jamais de `composer test`, `php artisan`, `vendor/bin/pint`
  ou `npm` directement sur l'hôte :
  - Tests : `docker compose run --rm test`
  - Serveur dev : `docker compose up app`
  - Pint : `docker compose run --rm test vendor/bin/pint --test`
  - Assets : `docker compose run --rm test npm run build`
  - Shell : `docker compose run --rm test sh`
- Base locale SQLite (`database/database.sqlite`), MySQL en production.

## Règles d'architecture (à ne pas casser)

- **Pas d'Eloquent pour la donnée métier.** Accès via repositories (`app/Models/*Repository`,
  e.g. `PlayersRepository`, `SeasonRepository`) sur le query builder. Aucun modèle Eloquent
  de domaine sans demande explicite.
- **L'API métier est ETF2L v2** (publique, ~60 req/min). Toujours passer par
  `app/Services/Etf2l/Etf2lApiClient` (cache en base + throttle + retries) — ne jamais
  appeler l'API en direct depuis un contrôleur ou une nouvelle tâche.
- **État dans des caches JSON.** Les données publiques pré-calculées vivent dans
  `config('palmares.data_dir')` = `storage/app/palmares` (via `palmares_data_path()`),
  régénérées depuis la DB (« self-healing » à la lecture si absentes/obsolètes). Ne jamais
  inventer d'autre emplacement de cache.
- **Pipeline = commandes `app:*` planifiées** (`routes/console.php`) en incrémental :
  seasons → tables → harvest → palmares → JSON. Chaque tâche en `withoutOverlapping()` ;
  toute nouvelle tâche/service suit le même modèle.
- **Concurrence** : `flock()` pour les calculs et écritures de fichiers (voir
  `ComputePalmaresService::runBackfill()` et `LeaderboardBuilder`).
- **Frontend vanilla** en `public/_css`, `public/_js`, cache-busting par
  `palmares_asset()`. Vite/Tailwind ne compile que `resources/css/app.css`.

## Vérifications obligatoires avant de finir

1. `composer test` (PHPUnit, SQLite `:memory:`)
2. `vendor/bin/pint` (style Laravel)
3. `npm run build` — seulement si `resources/` a changé

## Conventions de code

- `declare(strict_types=1)` + typage explicite sur tout paramètre/retour nouveau ou édité.
- PSR-12 (défauts Pint) : classes `App\`, repositories dans `app/Models`, logique métier
  dans `app/Services`, commandes `app/Console/Commands` (préfixe `app:*`).
- Commentaires/docblocks en français, typés et utiles.
- Tests PHPUnit avec attributes style Pest (`#[Test]`), dans `tests/Unit` et `tests/Feature`,
  fixtures réelles dans `tests/Fixtures/etf2l/`. Toujours `Http::fake()` — aucun appel
  réseau dans les tests.
- Ne pas committer `storage/app/palmares/`, `database/database.sqlite`, les logs ni les
  artefacts générés.

## Sécurité

- Pas de secrets stockés dans le code : l'API ETF2L est publique et sans clé, aucun token
  n'est manipulé ici. Ne pas introduire de clé/token sans le passer par `.env`.
- Les profils/URL Steam sont construits uniquement à partir de valeurs fournies par l'API
  (ids numériques) ; garder la validation/whitelist des entrées externes.