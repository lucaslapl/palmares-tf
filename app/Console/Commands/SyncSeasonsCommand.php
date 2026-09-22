<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Palmares\SeasonImporter;
use Illuminate\Console\Command;

final class SyncSeasonsCommand extends Command
{
    protected $signature = 'app:sync-seasons';

    protected $description = "Importe la liste des saisons 6v6/Highlander depuis l'API ETF2L";

    public function handle(SeasonImporter $importer): int
    {
        $this->info('Synchronisation des saisons en cours…');

        $count = $importer->run(function (array $competition): void {
            $this->line('  + '.($competition['name'] ?? '?'));
        });

        $this->info("Terminé : {$count} compétit(s) de saison connu(s).");

        return self::SUCCESS;
    }
}
