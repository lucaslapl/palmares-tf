<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Historique d'exécution des commandes app:* (table scheduled_command_runs).
 *
 * Alimenté de façon non invasive depuis les events console : début de commande,
 * fin (avec code de sortie) et échec d'une tâche planifiée. Sert au panel admin
 * pour suivre l'avancement du pipeline et détecter un run sans issue.
 */
final class CommandRunRepository
{
    /**
     * Ouvre un run pour la commande (début d'exécution).
     */
    public function start(string $command): void
    {
        DB::table('scheduled_command_runs')->insert([
            'command' => $command,
            'started_at' => time(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->prune();
    }

    /**
     * Clôt le run ouvert le plus récent de la commande avec son code de sortie.
     */
    public function finish(string $command, int $exitCode): void
    {
        $this->close($command, function (Builder $query) use ($exitCode): void {
            $query->update([
                'finished_at' => time(),
                'exit_code' => $exitCode,
                'error' => $exitCode === 0
                    ? null
                    : 'Command failed with exit code '.$exitCode,
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * Clôt le run ouvert le plus récent de la commande en échec (tâche
     * planifiée ou exception) en conservant le message d'erreur.
     */
    public function fail(string $command, Throwable $exception): void
    {
        $this->close($command, function (Builder $query) use ($exception): void {
            $query->update([
                'finished_at' => time(),
                'exit_code' => 1,
                'error' => mb_substr($exception->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * Derniers runs, du plus récent au plus ancien.
     */
    public function recent(int $limit = 20): array
    {
        return DB::table('scheduled_command_runs')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'command' => (string) $row->command,
                'started_at' => (int) $row->started_at,
                'finished_at' => $row->finished_at !== null ? (int) $row->finished_at : null,
                'exit_code' => $row->exit_code !== null ? (int) $row->exit_code : null,
                'error' => $row->error !== null ? (string) $row->error : null,
            ])
            ->all();
    }

    /**
     * Dernier run d'une commande (pour l'état de santé du scheduler).
     */
    public function lastRun(string $command): ?object
    {
        return DB::table('scheduled_command_runs')
            ->where('command', $command)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Supprime les runs plus vieux que la rétention configurée.
     */
    public function prune(): void
    {
        $retentionDays = max(1, (int) config('admin.runs_retention_days', 30));

        DB::table('scheduled_command_runs')
            ->where('started_at', '<', time() - $retentionDays * 86400)
            ->delete();
    }

    /**
     * Met à jour le run ouvert le plus récent de la commande, s'il existe.
     */
    private function close(string $command, callable $update): void
    {
        $row = DB::table('scheduled_command_runs')
            ->where('command', $command)
            ->whereNull('finished_at')
            ->orderByDesc('id')
            ->first();

        if ($row !== null) {
            $update(DB::table('scheduled_command_runs')->where('id', $row->id));
        }
    }
}
