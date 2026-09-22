<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SeasonsRepository;
use App\Services\Palmares\TableImporter;
use Illuminate\Console\Command;

final class SyncTablesCommand extends Command
{
    protected $signature = 'app:sync-tables
        {--force : Ré-ingère les tables de toutes les saisons, même déjà traitées}';

    protected $description = 'Importe les tables de classement final des saisons';

    public function handle(TableImporter $importer, SeasonsRepository $seasons): int
    {
        $mode = $this->option('force') ? 'force' : 'pending';
        $this->info("Synchronisation des tables ({$mode})…");

        $count = $importer->run(
            force: (bool) $this->option('force'),
            progress: function (object $season, int $rows): void {
                $this->line("  + [{$season->etf2l_competition_id}] {$season->name} — {$rows} ligne(s)");
            },
        );

        $pending = count($seasons->pendingTables());
        $this->info("Terminé : {$count} ligne(s) de table ingérée(s). Reste {$pending} saison(s) à traiter.");

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
