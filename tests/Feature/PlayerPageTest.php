<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PlayerPageTest extends TestCase
{
    use RefreshDatabase;

    private const PLAYER_ID = 70031;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useIsolatedDataDir();

        DB::table('players')->insert([
            'etf2l_id' => self::PLAYER_ID,
            'name' => 'kaptain',
            'country' => 'European',
            'steam_id64' => '76561198033727092',
            'avatar' => 'https://avatars.example/kaptain.jpg',
            'computed_at' => time(),
        ]);

        DB::table('seasons')->insert([
            'etf2l_competition_id' => 971,
            'name' => '6v6 Season 50 (Autumn 2025)',
            'category' => '6v6 Season',
            'format' => '6s',
            'archived' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('teams')->insert([
            'etf2l_team_id' => 32593,
            'name' => 'Witness Gaming',
            'country' => 'European',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('palmares')->insert([[
            'player_id' => 1,
            'season_id' => 1,
            'team_id' => 1,
            'competition_id' => 971,
            'format' => '6s',
            'competition_name' => '6v6 Season 50 (Autumn 2025)',
            'team_name' => 'Witness Gaming',
            'division_name' => 'Premiership',
            'placement' => 1,
            'medal' => 'gold',
            'season_time' => 1700000000,
        ]]);
    }

    #[Test]
    public function profile_lists_awards_and_stats(): void
    {
        $this->get('/players/'.self::PLAYER_ID)
            ->assertOk()
            ->assertSee('kaptain')
            ->assertSee('6v6 Season 50 (Autumn 2025)')
            ->assertSee('Witness Gaming')
            ->assertSee('Gold')
            ->assertSee('https://steamcommunity.com/profiles/76561198033727092');
    }

    #[Test]
    public function profile_links_to_etf2l_profile_flags_and_records(): void
    {
        $this->get('/players/'.self::PLAYER_ID)
            ->assertOk()
            ->assertSee('https://etf2l.org/forum/user/'.self::PLAYER_ID.'/', false)
            ->assertSee('https://etf2l.org/images/flags/European.gif', false)
            ->assertSee(route('seasons.show', ['season' => 1]), false)
            ->assertSee('https://etf2l.org/teams/32593/', false);
    }

    #[Test]
    public function profile_links_nations_cup_records_to_etf2l_archives(): void
    {
        // Les Nations Cup n'ont pas de saison interne : les records sans
        // season_id doivent rediriger vers les archives ETF2L (competition_id).
        DB::table('palmares')->insert([
            'player_id' => 1,
            'season_id' => null,
            'competition_id' => 928,
            'team_id' => 1,
            'format' => '6s',
            'competition_name' => '6v6 Nations Cup #10: Playoffs',
            'team_name' => 'Witness Gaming',
            'division_name' => '',
            'playoff_round' => 'Final',
            'season_time' => 1600000000,
        ]);

        $this->get('/players/'.self::PLAYER_ID)
            ->assertOk()
            ->assertSee('https://etf2l.org/etf2l/archives/928/', false);
    }

    #[Test]
    public function unknown_player_id_returns_404(): void
    {
        Http::fake([
            'api-v2.etf2l.org/*' => Http::response(['status' => ['code' => 404]], 200),
        ]);

        $this->get('/players/99999999')->assertNotFound();
    }
}
