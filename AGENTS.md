# AGENTS.md

## Vue d'ensemble du projet

« palmares.tf » — site Laravel 13 (PHP 8.3+) qui classe les joueurs TF2 compétitifs selon
les médailles de saison ETF2L (or/argent/bronze), toutes divisions, pondération 3/2/1.
Il vit dans son propre repo (pas de `bot/` ni `plugins/`). Tous les docblocks et
commentaires sont **en français** ; l'UI du site (et du panel admin) est en **anglais**.

## Environnement local

- **Docker est obligatoire.** Jamais de `composer test`, `php artisan`, `vendor/bin/pint`
  ou `npm` directement sur l'hôte :
  - Tests : `docker compose run --rm test`
  - Serveur dev : `docker compose up -d app`
  - Scheduler (tâches planifiées `app:*`) : `docker compose up -d scheduler`
  - Pint : `docker compose run --rm test vendor/bin/pint --test`
  - Assets : `docker compose run --rm test npm run build`
  - Shell : `docker compose run --rm test sh`
- Base locale SQLite (`database/database.sqlite`), MySQL en production.
- **Base de référence production** dans `database/reference/` (gitignoré) :
  `palmares.sql` (dump MySQL/MariaDB complet, ~1 Go) et `schema.sql` (CREATE TABLE
  extraits). Pour toute nouvelle feature du site qui consomme ou transforme des données
  métier, utiliser ce dump comme jeu de données réel de référence : y vérifier les
  schémas, volumes et cas limites (médailles, bans, divisions) et en dériver des extraits
  de test. Ne jamais le committer, ni remplacer `database/database.sqlite` par ce dump.
- Secrets du panel admin dans `.env` (jamais dans le code) :
  `ADMIN_ACCESS_TOKEN`, `ADMIN_USERNAME`, `ADMIN_PASSWORD_HASH` (hash bcrypt généré via
  `php artisan app:admin-hash`).

## Règles d'architecture (à ne pas casser)

- **Pas d'Eloquent pour la donnée métier.** Accès via repositories (`app/Models/*Repository`,
  e.g. `PlayersRepository`, `SeasonRepository`) sur le query builder. Aucun modèle Eloquent
  de domaine sans demande explicite. (Seuls les squelettes Laravel comme `User` sont en
  Eloquent.)
- **L'API métier est ETF2L v2** (publique, ~60 req/min). Toujours passer par
  `app/Services/Etf2l/Etf2lApiClient` (cache en base + throttle + retries) — ne jamais
  appeler l'API en direct depuis un contrôleur ou une nouvelle tâche.
- **État dans des caches JSON.** Les données publiques pré-calculées vivent dans
  `config('palmares.data_dir')` = `storage/app/palmares` (via `palmares_data_path()`),
  régénérées depuis la DB (« self-healing » à la lecture si absentes/obsolètes). Ne jamais
  inventer d'autre emplacement de cache.
- **Pipeline = commandes `app:*` planifiées** (`routes/console.php`) en incrémental :
  seasons → tables → harvest → palmares → JSON, plus `app:backfill` (remplissage initial
  par tranches `--runtime`, verrou `backfill.lock`, exit code 4 = terminé) et `app:status`
  (instantané du pipeline). Chaque tâche planifiée en `withoutOverlapping()` ; toute
  nouvelle tâche/service suit le même modèle. Les runs des commandes `app:*` sont tracés
  dans `scheduled_command_runs` (rétention `config/admin.php`), consommés par le panel
  admin et `app:status`.
- **Panel admin** : protégé par deux couches — lien secret `/admin/{token}`
  (`ADMIN_ACCESS_TOKEN`, middleware `admin.magic` qui pose le flag de session) puis login
  (`ADMIN_USERNAME` / `ADMIN_PASSWORD_HASH`, middleware `admin.access`, rate limiter
  `admin-login`). Sans lien secret, tout `/admin/*` renvoie 404. Les contrôleurs/panels
  admin lisent l'état via `AdminDashboardRepository` / `CommandRunRepository` uniquement.
- **Bans ETF2L** : réduits à la date de fin la plus lointaine (`ban_until` sur `players`,
  migration dédiée), extraits du payload `/player/{id}` et propagés par le re-harvest des
  profils (`app:compute-palmares` / `app:backfill`). Badge « Banned » sur le leaderboard
  et le profil quand le ban est actif (`ban_until > now()`).
- **Entrées externes whitelistées** : les drapeaux pays sont des URLs construites par
  `country_flag_url()` uniquement à partir de libellés alphabétiques validés ; les
  profils/URL Steam uniquement à partir d'ids numériques fournis par l'API.
- **Concurrence** : `flock()` pour les calculs et écritures de fichiers (voir
  `ComputePalmaresService::runBackfill()` et `LeaderboardBuilder`).
- **Frontend vanilla** en `public/_css`, `public/_js`, cache-busting par
  `palmares_asset()`. Vite/Tailwind ne compile que `resources/css/app.css`. Le panel admin
  a sa feuille dédiée `public/_css/admin.css` et ses vues `resources/views/admin/`.

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
- Ne pas committer `storage/app/palmares/`, `database/database.sqlite`, le dump de
  référence `database/reference/`, les logs ni les artefacts générés.

## Commits

- **Un commit à chaque changement** : chaque modification fonctionnelle ou corrective
  aboutit à son propre commit, regroupé par changement logique (pas de gros commits
  fourre-tout, pas de travail non committé qui traîne).
- **Convention de message respectée dans ce repo** : message **en anglais**, court, tout
  en minuscules, qui décrit directement le changement — sans préfixe « conventional
  commits » ni portée. Modèles réels de l'historique :
  `added bans`, `fixed compute palmares script being stuck`, `added admin panel`,
  `optimizing etf2l data backfill`, `fixed bug with sync-tables cron job`.

## Sécurité

- Pas de secrets stockés dans le code : l'API ETF2L est publique et sans clé ; les
  identifiants du panel admin vivent dans `.env`. Ne pas introduire de clé/token sans le
  passer par `.env`.
- Les profils/URL Steam sont construits uniquement à partir de valeurs fournies par l'API
  (ids numériques) ; garder la validation/whitelist des entrées externes.
- Le hash du mot de passe admin se génère avec `php artisan app:admin-hash` — ne jamais
  committer un hash ou un token réel.
