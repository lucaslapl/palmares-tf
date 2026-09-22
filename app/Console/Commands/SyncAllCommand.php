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

        if ($this->option('force')) {
            $chain[] = 'app:sync-tables --force';
        } else {
            $chain[] = 'app:sync-tables';
        }

        array_push($chain, 'app:harvest-players', 'app:compute-palmares', 'app:generate-json');

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
