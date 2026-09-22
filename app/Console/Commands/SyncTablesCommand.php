<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SeasonsRepository;
use App\Services\Palmares\TableImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class SyncTablesCommand extends Command
{
    protected $signature = 'app:sync-tables
        {--force : Ré-ingère les tables de toutes les saisons, même déjà traitées}
        {--reset : Repasse les saisons en attente (ingested_at=NULL) avant l\'import}
        {--limit=0 : Nombre maximum de saisons à traiter (0 = toutes)}';

    protected $description = 'Importe les tables de classement final des saisons';

    public function handle(TableImporter $importer, SeasonsRepository $seasons): int
    {
        $force = (bool) $this->option('force');
        $reset = (bool) $this->option('reset');
        $limit = (int) max(0, (int) $this->option('limit'));

        if ($reset) {
            $seasons->resetTableIngestion();
            $this->line('Toutes les saisons sont repassées en attente de tables.');
            Log::info('app:sync-tables : ingestion réinitialisée');
        }

        $mode = $force ? 'force' : 'pending';
        $this->info("Synchronisation des tables ({$mode})…");

        $count = $importer->run(
            force: $force,
            progress: function (object $season, int $rows): void {
                $this->line("  + [{$season->etf2l_competition_id}] {$season->name} — {$rows} ligne(s)");
            },
            limit: $limit,
        );

        $pending = count($seasons->pendingTables());
        $this->info("Terminé : {$count} ligne(s) de table ingérée(s). Reste {$pending} saison(s) à traiter.");
        Log::info('app:sync-tables terminée', ['rows' => $count, 'pending_seasons' => $pending, 'force' => $force, 'limit' => $limit]);

        $errors = $importer->errors();
        if ($errors !== []) {
            $this->warn(count($errors).' saison(s) en échec (retentées à la prochaine passe) :');
            foreach (array_slice($errors, 0, 10) as $error) {
                $this->line('  - '.$error);
            }
        }

        return self::SUCCESS;
    }
}
