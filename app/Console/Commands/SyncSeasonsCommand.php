<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Palmares\SeasonImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class SyncSeasonsCommand extends Command
{
    protected $signature = 'app:sync-seasons
        {--limit=0 : Nombre maximum de compétitions à traiter (0 = toutes)}';

    protected $description = "Importe la liste des saisons 6v6/Highlander depuis l'API ETF2L";

    public function handle(SeasonImporter $importer): int
    {
        $limit = (int) max(0, (int) $this->option('limit'));
        $this->info('Synchronisation des saisons en cours…');

        $count = $importer->run(function (array $competition): void {
            $this->line('  + '.($competition['name'] ?? '?'));
        }, $limit);

        $this->info("Terminé : {$count} compétit(s) de saison connu(s).");
        Log::info('app:sync-seasons terminée', ['imported' => $count, 'limit' => $limit]);

        return self::SUCCESS;
    }
}
