<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Palmares\LeaderboardBuilder;
use Illuminate\Console\Command;

final class GenerateJsonCommand extends Command
{
    protected $signature = 'app:generate-json';

    protected $description = 'Régénère les JSON publics (leaderboards + index de recherche)';

    public function handle(LeaderboardBuilder $builder): int
    {
        $this->info('Génération des JSON…');

        $stats = $builder->rebuildAll();

        $this->info(
            'Leaderboard global : '.$stats['leaderboard_all']
            .' — 6v6 : '.$stats['leaderboard_6v6']
            .' — 9v9 : '.$stats['leaderboard_9v9']
            .' — index joueurs : '.$stats['players_index'],
        );

        return self::SUCCESS;
    }
}
