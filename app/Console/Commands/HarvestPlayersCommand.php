<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Palmares\RosterHarvester;
use Illuminate\Console\Command;

final class HarvestPlayersCommand extends Command
{
    protected $signature = 'app:harvest-players';

    protected $description = 'Récolte les joueurs candidats (rosters et transferts des équipes classées)';

    public function handle(RosterHarvester $harvester): int
    {
        $this->info('Récolte des joueurs candidats en cours… (ceci peut prendre un moment)');

        $inserted = $harvester->run(function (array $team): void {
            $this->line('  + équipe '.($team['name'] ?? '?'));
        });

        $this->info("Terminé : {$inserted} joueur(s) nouvellement découvert(s).");
        $this->line('Pensez à lancer app:compute-palmares pour calculer leurs palmarès.');

        return self::SUCCESS;
    }
}
