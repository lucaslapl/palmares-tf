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
  round de playoffs atteint), même sans récompense, avec **drapeau pays**, avatar et
  **lien vers son profil ETF2L**. Les profils sont **calculés à la demande** lors de la
  première visite (philosophie « self-healing » : caches en JSON régénérés depuis la
  base).
- **Bans ETF2L** : badge « Banned » sur le leaderboard et le profil d'un joueur tant que
  son ban est actif (date de fin extraite de l'API, rafraîchie à chaque re-harvest).
- **Recherche** de joueurs par nom (index JSON pré-généré, autocomplete `/api/search`).
- **Pages « Results »** (ex-saisons) : tables de classement final par division, podiums
  en tête.
- **Panel admin de monitoring** (voir ci-dessous) : santé du pipeline, volumes en base,
  fraîcheur des JSON, journal des tâches.

## Pipeline de données

Source : API ETF2L v2 (`api-v2.etf2l.org`), publique, limitée (~60 req/min, throttling
1,1 s imposé), réponses cachées en base (`etf2l_api_cache`) avec TTL par endpoint.

```
populated par              consommé par
─────────────────────      ──────────────
app:sync-seasons           saisons (compétitions de ligue)
app:sync-tables            tables de classement final (ach 1/2/3) → saisons + équipes
app:harvest-players        vivier de joueurs (rosters + transferts des équipes classées)
app:compute-palmares       palmarès joueur par joueur + bans ETF2L (ban_until)
app:generate-json          leaderboards + index de recherche → storage/app/palmares/*.json

app:backfill               tout le pipeline par tranches --runtime (remplissage initial)
app:sync-all               tout le pipeline incrémental d'un coup (--force pour re-traiter)
app:status                 instantané de la progression du pipeline
```

Le moteur de calcul (`ComputePalmaresService`) croise les résultats de matches d'un
joueur avec les tables de classement (`ach`) pour en déduire les podiums, détecte les
rounds de playoffs significatifs (Grand Final, finales de bracket, demi-finales…) et
déduplique par saison logique (saison régulière + playoffs séparés). Les Nations Cup ne
comptent qu'à partir du podium, déduit des finales. Le calcul tourne par lots bornés en
mémoire et en temps (`--runtime`), et le backfill ne retient qu'un verrou `flock` /
`backfill.lock` pour être relançable en parallèle sans doublon.

Les JSON publics (`storage/app/palmares/`) sont écrits de façon atomique sous verrou
(`flock`), refroidis/se régénèrent eux-mêmes à la lecture s'ils sont absents. En
production, MySQL et planification via `routes/console.php` (toutes les tâches en
`withoutOverlapping`). Chaque exécution d'une commande `app:*` est tracée dans la table
`scheduled_command_runs` (rétention 30 jours), qui alimente le panel admin et
`app:status`.

### Scheduler et observabilité

Le scheduler doit être déclenché explicitement, sinon les tâches planifiées `app:*`
ne s'exécutent jamais :

- **Local** : `docker compose up -d scheduler` (service qui tourne `php artisan schedule:work`).
- **Production** : une ligne cron par minute, e.g.
  `* * * * * cd /srv/palmares && php artisan schedule:run >> storage/logs/schedule-cron.log 2>&1`

La sortie et les erreurs de chaque tâche planifiée sont appendées (`appendOutputTo`) dans
`storage/logs/schedule.log`, et le moindre échec de tâche est aussi tracé dans
`storage/logs/laravel.log` (events `ScheduledTaskFailed`).

Pour un instantané à la demande de la progression du pipeline :

```bash
php artisan app:status
```

Il affiche volumes en base (saisons, équipes, joueurs, palmarès), travail restant,
fraîcheur du cache API et des JSON publics, et les dernières lignes de
`storage/logs/schedule.log`.

### Premier remplissage (backfill)

Deux options :

- Manuel : `php artisan app:sync-seasons` puis `app:sync-tables`, `app:harvest-players`
  (découpables avec `--limit=`), `app:compute-palmares --exit-on-empty --runtime=3600`
  en boucle jusqu'au code de sortie 4 (plus rien en attente), enfin `app:generate-json`.
  Ou tout d'un coup : `php artisan app:sync-all`.
- Continu : `bin/backfill.sh` enchaîne `app:backfill --runtime` jusqu'à épuisement du
  pipeline (code 4 = terminé), avec tolérance aux échecs (abandon après 5 échecs
  consécutifs) et `php -d memory_limit=-1`. En production, un service systemd est fourni :

```bash
sudo cp deploy/palmares-backfill.service /etc/systemd/system/
sudo systemctl daemon-reload && sudo systemctl enable --now palmares-backfill
# Journal : storage/logs/backfill.log
```

## Panel admin

Interface de monitoring protégée par **deux couches** :

1. **Lien secret** `/admin/{token}` (`ADMIN_ACCESS_TOKEN` dans `.env`, comparé via
   `hash_equals`) qui pose un flag « magic » en session — sans lui, tout `/admin/*`
   renvoie 404 et le panel reste invisible aux scanners.
2. **Login classique** (`ADMIN_USERNAME` / `ADMIN_PASSWORD_HASH`, hash bcrypt généré
   par `php artisan app:admin-hash`), POST borné par le rate limiter `admin-login`.

Le dashboard affiche l'état du pipeline : volumes en base (saisons, équipes, joueurs,
palmarès, médailles), joueurs restant à calculer, santé de chaque tâche planifiée
(ok / overdue / failed / never, date du dernier run, exit code), fraîcheur des JSON
publics et taille de la base. Une page `/admin/logs` expose les dernières lignes de
`storage/logs/schedule.log`. Toutes les données viennent de la lecture seule de la DB
(`AdminDashboardRepository`, `CommandRunRepository`).

## Environnement local

Docker est obligatoire — ne lancez jamais `composer test`, `php artisan`, `pint` ou `npm`
directement sur l'hôte.

```bash
# Lancer l'app (http://localhost:8000)
docker compose up -d app

# Lancer le scheduler des tâches planifiées (app:sync-*, app:compute-palmares…)
docker compose up -d scheduler

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

# Générer le hash du mot de passe admin
docker compose run --rm test php artisan app:admin-hash
```

Première installation : `composer install && php artisan key:generate && touch database/database.sqlite && php artisan migrate`, puis renseigner `ADMIN_ACCESS_TOKEN`, `ADMIN_USERNAME` et `ADMIN_PASSWORD_HASH` dans `.env` (voir `.env.example`).

## Architecture

- **Pas d'Eloquent pour la donnée métier** : accès via des repositories (`app/Models/*Repository`)
  sur le query builder. Seul le squelette Laravel (`User`) est en Eloquent.
- **Panel admin** : middlewares `admin.magic` / `admin.access`, vues `resources/views/admin/`,
  style dédié `public/_css/admin.css`, aucun secret codé en dur (tout via l'environnement).
- **Tests** : PHPUnit avec attributes « style Pest » (`#[Test]`), suites `tests/Unit` et
  `tests/Feature` (dont `AdminAccessTest`, `AdminDashboardTest`, `CommandRunTrackingTest`),
  fixtures JSON capturées dans `tests/Fixtures/etf2l/`, `Http::fake()` pour ne jamais
  toucher le réseau.
- **Frontend vanilla** : `public/_css/app.css`, `public/_js/search.js`, cache-busting via
  `palmares_asset()`. Vite/Tailwind ne compile que `resources/css/app.css`.
- **Concurrence** : `flock()` (calculs, écriture des JSON, backfill) et `withoutOverlapping()`.
- **Entrées externes validées** : drapeaux via `country_flag_url()` (libellés alphabétiques
  seulement), URLs Steam à partir d'ids numériques fournis par l'API.

## Tests

```bash
docker compose run --rm test
```

## Licence

Projet privé — aucune réutilisation sans autorisation.
