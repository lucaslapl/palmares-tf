<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Palmares\ComputePalmaresService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class ComputePalmaresCommand extends Command
{
    protected $signature = 'app:compute-palmares
        {--limit= : Nombre maximum de joueurs à traiter (0 = pas de limite)}
        {--runtime=1500 : Durée maximale d\'exécution en secondes}
        {--exit-on-empty : Sort avec le code 4 quand plus aucun joueur n\'est en attente (usage backfill systemd)}';

    protected $description = 'Calcule le palmarès des joueurs en attente (avec cache et throttling API)';

    public function handle(ComputePalmaresService $service): int
    {
        if ($this->option('exit-on-empty') && ! $service->hasPendingPlayers()) {
            $this->info('Aucun joueur en attente : backfill terminé.');

            return 4;
        }

        $limit = (int) max(0, (int) $this->option('limit'));
        $runtime = (int) max(1, (int) $this->option('runtime'));

        $this->info('Calcul des palmarès en cours…');

        try {
            $stats = $service->runBackfill(
                progress: function (object $player): void {
                    $this->line('  + '.($player->name ?? $player->etf2l_id).' ('.$player->etf2l_id.')');
                },
                limit: $limit,
                maxRuntimeS: $runtime,
            );
        } catch (RuntimeException $e) {
            // Contention du verrou : une autre exécution (backfill systemd,
            // passe précédente) travaille déjà. Ce n'est pas un échec : on
            // skipe silencieusement pour que le scheduler ne flagge rien.
            $this->info($e->getMessage());

            Log::info('app:compute-palmares : une autre exécution est active, cette passe est ignorée.', [
                'detail' => $e->getMessage(),
            ]);

            return self::SUCCESS;
        }

        $this->info("Calculé : {$stats['computed']} — en échec : {$stats['failed']}");
        Log::info('app:compute-palmares terminée', [
            'computed' => $stats['computed'],
            'failed' => $stats['failed'],
            'skipped' => $stats['skipped'],
            'limit' => $limit,
            'runtime' => $runtime,
        ]);

        if ($stats['failed'] > 0) {
            $this->error('Erreurs ('.count($stats['errors']).') :');
            foreach (array_slice($stats['errors'], 0, 10) as $error) {
                $this->error('  - '.$error);
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
