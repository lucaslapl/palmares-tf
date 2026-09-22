<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Palmares\RosterHarvester;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class HarvestPlayersCommand extends Command
{
    protected $signature = 'app:harvest-players
        {--limit=0 : Nombre maximum d\'équipes à traiter (0 = toutes)}';

    protected $description = 'Récolte les joueurs candidats (rosters et transferts des équipes classées)';

    public function handle(RosterHarvester $harvester): int
    {
        $limit = (int) max(0, (int) $this->option('limit'));
        $this->info('Récolte des joueurs candidats en cours… (ceci peut prendre un moment)');

        $inserted = $harvester->run(function (array $team): void {
            $this->line('  + équipe '.($team['name'] ?? '?'));
        }, $limit);

        $this->info("Terminé : {$inserted} joueur(s) nouvellement découvert(s).");
        $this->line('Pensez à lancer app:compute-palmares pour calculer leurs palmarès.');
        Log::info('app:harvest-players terminée', ['new_players' => $inserted, 'limit' => $limit]);

        $errors = $harvester->errors();
        if ($errors !== []) {
            $this->warn(count($errors).' équipe(s) en échec (retentées à la prochaine passe) :');
            foreach (array_slice($errors, 0, 10) as $error) {
                $this->line('  - '.$error);
            }
        }

        return self::SUCCESS;
    }
}
