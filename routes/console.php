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

$log = storage_path('logs/schedule.log');

Schedule::command('app:sync-seasons')->everySixHours()->withoutOverlapping()->appendOutputTo($log);

Schedule::command('app:sync-tables')->everySixHours()->withoutOverlapping()->appendOutputTo($log);

Schedule::command('app:harvest-players')->everySixHours()->withoutOverlapping()->appendOutputTo($log);

Schedule::command('app:compute-palmares')->everyThirtyMinutes()->withoutOverlapping(180)->appendOutputTo($log);

Schedule::command('app:generate-json')->everyThreeHours()->withoutOverlapping()->appendOutputTo($log);
