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

        DB::table('palmares')->insert([[
            'player_id' => 1,
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
    public function unknown_player_id_returns_404(): void
    {
        Http::fake([
            'api-v2.etf2l.org/*' => Http::response(['status' => ['code' => 404]], 200),
        ]);

        $this->get('/players/99999999')->assertNotFound();
    }
}
