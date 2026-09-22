<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PipelineStatusCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useIsolatedDataDir();
    }

    #[Test]
    public function reports_pipeline_state_when_empty(): void
    {
        $this->artisan('app:status')
            ->assertExitCode(0)
            ->expectsOutputToContain('État du pipeline API ETF2L');
    }

    #[Test]
    public function reports_pending_work_and_volumes(): void
    {
        DB::table('seasons')->insert([
            'etf2l_competition_id' => 971,
            'name' => '6v6 Season 50 (Autumn 2025)',
            'category' => '6v6 Season',
            'format' => '6s',
            'archived' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('players')->insert([
            'etf2l_id' => 70031,
            'name' => 'kaptain',
            'country' => 'Netherlands',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('etf2l_api_cache')->insert([
            'url' => 'https://api.example.test/ping',
            'payload' => '{}',
            'fetched_at' => time() - 120,
        ]);

        $this->artisan('app:status')
            ->assertExitCode(0)
            ->expectsOutputToContain('1 en attente de tables')
            ->expectsOutputToContain('1 en attente / 0 calculés');
    }
}
