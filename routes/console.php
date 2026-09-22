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
*/

Schedule::command('app:sync-seasons')->everySixHours()->withoutOverlapping();

Schedule::command('app:sync-tables')->everySixHours()->withoutOverlapping();

Schedule::command('app:harvest-players')->everySixHours()->withoutOverlapping();

Schedule::command('app:compute-palmares')->everyThirtyMinutes()->withoutOverlapping(180);

Schedule::command('app:generate-json')->everyThreeHours()->withoutOverlapping();
