<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Etf2l\Etf2lApiClient;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Le client API est lié depuis le config plutôt que résolu avec ses
        // valeurs par défaut : c'est le seul endroit (avec palmares.php) qui
        // pilote l'URL, le délai de throttle et le timeout.
        $this->app->bind(Etf2lApiClient::class, function (): Etf2lApiClient {
            return new Etf2lApiClient(
                (string) config('palmares.etf2l.base_url'),
                (string) config('palmares.etf2l.user_agent'),
                (float) config('palmares.etf2l.request_delay_s'),
                (int) config('palmares.etf2l.http_timeout_s'),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Trace systématique des tâches planifiées : un échec silencieux du
        // harvest ne doit plus passer inaperçu (voir aussi app:status).
        Event::listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event): void {
            Log::error('Tâche planifiée en échec : '.$event->task->getSummaryForDisplay(), [
                'command' => $event->task->command,
                'error' => $event->error->getMessage(),
            ]);
        });

        Event::listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event): void {
            Log::info('Tâche planifiée terminée : '.$event->task->getSummaryForDisplay(), [
                'command' => $event->task->command,
            ]);
        });
    }
}
