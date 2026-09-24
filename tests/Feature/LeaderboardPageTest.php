<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PalmaresRepository;
use App\Models\PlayersRepository;
use App\Services\Palmares\LeaderboardBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class LeaderboardPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useIsolatedDataDir();

        DB::table('players')->insert(['etf2l_id' => 1, 'name' => 'Alpha Player', 'country' => 'AU', 'ban_until' => time() + 86400]);
        DB::table('players')->insert(['etf2l_id' => 2, 'name' => 'Beta Player', 'country' => 'GB']);

        DB::table('palmares')->insert([[
            'player_id' => 1, 'competition_id' => 10, 'format' => '6s',
            'competition_name' => 'Season 10', 'team_name' => 'Alpha Team',
            'division_name' => 'Premiership', 'placement' => 1, 'medal' => 'gold',
            'season_time' => 1700000000,
        ], [
            'player_id' => 2, 'competition_id' => 11, 'format' => '6s',
            'competition_name' => 'Season 11', 'team_name' => 'Beta Team',
            'division_name' => 'Premiership', 'placement' => 2, 'medal' => 'silver',
            'season_time' => 1700000001,
        ]]);

        (new LeaderboardBuilder(new PalmaresRepository, new PlayersRepository))->rebuildAll();
    }

    #[Test]
    public function leaderboard_page_lists_players(): void
    {
        $this->get('/leaderboard')
            ->assertOk()
            ->assertSee('Alpha Player')
            ->assertSee('Beta Player')
            // Alpha a un ban ETF2L actif : badge visible.
            ->assertSee('Banned');
    }

    #[Test]
    public function leaderboard_filter_only_shows_9v9(): void
    {
        $this->get('/leaderboard?format=9v9')
            ->assertOk()
            ->assertSee('No rankings available yet');
    }

    #[Test]
    public function home_page_shows_top_players(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Alpha Player');
    }
}
