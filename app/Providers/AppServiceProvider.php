<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\CommandRunRepository;
use App\Services\Etf2l\Etf2lApiClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
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
                'error' => $event->exception->getMessage(),
            ]);

            // Un échec de tâche planifiée peut ne pas déclencher CommandFinished
            // (exception propagée) : on clôt alors le run ouvert explicitement.
            if (preg_match('/app:[a-z-]+/', (string) $event->task->command, $matches) === 1) {
                app(CommandRunRepository::class)->fail($matches[0], $event->exception);
            }
        });

        Event::listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event): void {
            Log::info('Tâche planifiée terminée : '.$event->task->getSummaryForDisplay(), [
                'command' => $event->task->command,
            ]);
        });

        // Historique des exécutions app:* (table scheduled_command_runs),
        // qu'elles soient planifiées ou lancées à la main. Ce suivi est non
        // invasif : les commandes n'ont rien à déclarer.
        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            if (str_starts_with($event->command, 'app:') && $event->command !== 'app:admin-hash') {
                app(CommandRunRepository::class)->start($event->command);
            }
        });

        Event::listen(CommandFinished::class, function (CommandFinished $event): void {
            if (str_starts_with($event->command, 'app:') && $event->command !== 'app:admin-hash') {
                app(CommandRunRepository::class)->finish($event->command, (int) $event->exitCode);
            }
        });

        // Limite du formulaire de connexion du panel admin (5 tentatives/min/IP).
        RateLimiter::for('admin-login', fn (Request $request): Limit => Limit::perMinute(5)->by(
            (string) $request->ip(),
        ));
    }
}
