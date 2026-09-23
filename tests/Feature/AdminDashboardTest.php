<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useIsolatedDataDir();
        $this->withSession(['admin_magic_ok' => true, 'admin_authenticated' => true]);
    }

    #[Test]
    public function dashboard_renders_pipeline_counts(): void
    {
        DB::table('players')->insert([
            [
                'etf2l_id' => 1,
                'name' => 'Alice',
                'country' => 'Netherlands',
                'computed_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'etf2l_id' => 2,
                'name' => 'Bob',
                'country' => 'Germany',
                'computed_at' => time(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('seasons')->insert([
            'etf2l_competition_id' => 1,
            'name' => '6v6 Season 50',
            'category' => '6v6 Season',
            'format' => '6s',
            'archived' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('palmares')->insert([
            'player_id' => 2,
            'team_name' => 'Team A',
            'placement' => 1,
            'medal' => 'gold',
        ]);

        $this->get('/admin')
            ->assertOk()
            ->assertSee('Pipeline overview')
            ->assertSee('1')
            ->assertSee('2')
            ->assertSee('Scheduler health')
            ->assertSee('Data quality');
    }

    #[Test]
    public function dashboard_reports_quality_anomalies(): void
    {
        DB::table('players')->insert([
            [
                'etf2l_id' => 1,
                'name' => null,
                'country' => null,
                'computed_at' => time(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->get('/admin')
            ->assertOk()
            ->assertSee('Players never computed')
            ->assertSee('Players without name');
    }

    #[Test]
    public function logs_page_shows_schedule_inactivity_hint(): void
    {
        $this->get('/admin/logs')
            ->assertOk()
            ->assertSee('No scheduler log yet');
    }
}
