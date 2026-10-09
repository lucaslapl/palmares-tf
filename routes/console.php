<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Planification
|--------------------------------------------------------------------------
|
| Le pipeline est webhook-driven pour les données temps réel ; ici les tâches
| planifiées sont des filets de sécurité incrémentaux. Toutes sont protégées
| par withoutOverlapping() pour éviter les exécutions concurrentes.
|
| La sortie (et les erreurs) de chaque tâche sont redirigées vers
| storage/logs/schedule.log, horodatées par le scheduler, pour suivre en
| direct le bon déroulement du harvest. Pensez à déclencher le scheduler :
|  - local : docker compose up -d scheduler   (php artisan schedule:work)
|  - prod  : crontab « * * * * * php artisan schedule:run »
|
*/

/*
 * Intervalles choisis en régime écrasé (backfill terminé) :
 *  - les imports (seasons/tables/harvest) sont en cache 7 j côté client API,
 *    donc un rythme quotidien suffit ; ils sont décalés de 15 min dans
 *    l'ordre du pipeline (seasons → tables → harvest) pour ne pas démarrer
 *    tous à la même minute ;
 *  - compute-palmares reste à 30 min : c'est lui qui absorbe le flux des
 *    joueurs qui dépassent la fenêtre de staleness (7 j), et sansOverlapping(180)
 *    couvre les passes longues ;
 *  - generate-json à 6 h suit les écritures base des autres tâches ;
 *  - compute-stats (toutes les 6 h) régénère les stats communautaires,
 *    elles changent aussi lentement que les JSON publics.
 */

$log = storage_path('logs/schedule.log');

Schedule::command('app:sync-seasons')->daily()->withoutOverlapping()->appendOutputTo($log);

Schedule::command('app:sync-season-groups')->dailyAt('00:10')->withoutOverlapping()->appendOutputTo($log);

Schedule::command('app:sync-tables')->dailyAt('00:15')->withoutOverlapping()->appendOutputTo($log);

Schedule::command('app:harvest-players')->dailyAt('00:30')->withoutOverlapping()->appendOutputTo($log);

Schedule::command('app:compute-palmares')->everyThirtyMinutes()->withoutOverlapping(180)->appendOutputTo($log);

Schedule::command('app:generate-json')->everySixHours()->withoutOverlapping()->appendOutputTo($log);

Schedule::command('app:compute-stats')->everySixHours()->withoutOverlapping()->appendOutputTo($log);
