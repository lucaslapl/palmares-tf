<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Commande de commodité : chaîne le pipeline complet de synchronisation.
 */
final class SyncAllCommand extends Command
{
    protected $signature = 'app:sync-all
        {--force : Ré-ingère tout (tables + palmarès) même si déjà traité}';

    protected $description = 'Exécute l\'intégralité du pipeline (saisons → tables → joueurs → palmarès → JSON)';

    public function handle(): int
    {
        $chain = ['app:sync-seasons'];
        $force = (bool) $this->option('force');

        $chain[] = $force ? 'app:sync-tables --force' : 'app:sync-tables';

        array_push($chain, 'app:harvest-players');

        // --force réarme aussi les palmarès (recalcul complet des joueurs).
        $chain[] = $force ? 'app:compute-palmares --force' : 'app:compute-palmares';

        $chain[] = 'app:generate-json';

        foreach ($chain as $command) {
            $this->info("==> {$command}");
            $result = $this->call($command);

            if ($result !== self::SUCCESS) {
                $this->error("Echec de {$command}.");

                return $result;
            }
        }

        $this->info('Pipeline terminé.');

        return self::SUCCESS;
    }
}
