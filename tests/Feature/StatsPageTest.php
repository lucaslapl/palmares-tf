<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\StatsRepository;
use App\Services\Palmares\StatsBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class StatsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useIsolatedDataDir();

        // Un stats.json laissé par un test précédent serait servi tel quel
        // (self-healing uniquement si absent/obsolète).
        @unlink(palmares_data_path('stats.json'));
    }

    #[Test]
    public function stats_page_renders_charts_from_generated_json(): void
    {
        DB::table('players')->insert(['etf2l_id' => 1, 'name' => 'Alpha']);
        DB::table('seasons')->insert([
            'id' => 1,
            'etf2l_competition_id' => 100,
            'name' => 'Season 10 (Autumn 2010)',
            'category' => '6v6 Season',
            'format' => '6s',
            'archived' => true,
        ]);
        DB::table('palmares')->insert([
            'player_id' => 1, 'season_id' => 1, 'competition_id' => 100, 'format' => '6s',
            'competition_name' => 'Season 10 (Autumn 2010)', 'placement' => 1,
            'medal' => 'gold', 'season_time' => 1289043200,
        ]);
        DB::table('participations')->insert([
            'player_id' => 1, 'season_id' => 1, 'competition_id' => 100, 'format' => '6s',
            'competition_name' => 'Season 10 (Autumn 2010)', 'season_time' => 1289043200,
        ]);

        (new StatsBuilder(new StatsRepository))->rebuild();

        $this->get('/stats')
            ->assertOk()
            ->assertSee('Community stats')
            ->assertSee('chart-years')
            ->assertSee('Players who played')
            ->assertSee('Medal winners')
            ->assertSee('Season 10');
    }

    #[Test]
    public function stats_page_handles_empty_database(): void
    {
        $this->get('/stats')
            ->assertOk()
            ->assertSee('No statistics available yet');
    }
}
