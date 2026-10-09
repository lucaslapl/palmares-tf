<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Palmares\StatsBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Régénère le JSON des statistiques communautaires (stats.json), consommé
 * par la page /stats. Suit app:generate-json dans le pipeline planifié.
 */
final class ComputeStatsCommand extends Command
{
    protected $signature = 'app:compute-stats';

    protected $description = 'Régénère les statistiques communautaires (stats.json)';

    public function handle(StatsBuilder $builder): int
    {
        $this->info('Calcul des statistiques communautaires…');

        try {
            $payload = $builder->rebuild();
        } catch (Throwable $e) {
            $this->error('Échec du calcul des stats : '.$e->getMessage());
            Log::error('app:compute-stats échouée', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }

        $seasons6 = count($payload['seasons']['6s'] ?? []);
        $seasons9 = count($payload['seasons']['9v9'] ?? []);
        $this->info("Séries construites : {$seasons6} saisons 6v6, {$seasons9} saisons 9v9.");
        Log::info('app:compute-stats terminée', [
            'seasons_6s' => $seasons6,
            'seasons_9v9' => $seasons9,
            'years' => count($payload['years'] ?? []),
        ]);

        return self::SUCCESS;
    }
}
