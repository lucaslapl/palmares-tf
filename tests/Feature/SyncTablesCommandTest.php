<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SeasonsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SyncTablesCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function reset_re_arm_seasons_incorrectly_marked_ingested(): void
    {
        foreach ([971 => '6v6 Season 50 (Autumn 2025)', 999 => 'Saison suivante'] as $comp => $name) {
            DB::table('seasons')->insert([
                'etf2l_competition_id' => $comp,
                'name' => $name,
                'category' => '6v6 Season',
                'format' => '6s',
                'archived' => false,
                'ingested_at' => time(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Http::fake(['*/competition/*/tables*' => Http::response(['tables' => []])]);

        $this->artisan('app:sync-tables', ['--reset' => true, '--limit' => 1])
            ->expectsOutputToContain('saisons sont repassées en attente')
            ->assertExitCode(0);

        $this->assertCount(2, (new SeasonsRepository)->pendingTables());
    }
}
