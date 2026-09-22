<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ComputePalmaresCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useIsolatedDataDir();
    }

    #[Test]
    public function exit_on_empty_stops_with_code_4_when_nothing_pending(): void
    {
        // L'application peut s'arrêter proprement même sans joueurs en attente,
        // ce qui termine la boucle de backfill systemd.
        $this->artisan('app:compute-palmares', ['--exit-on-empty' => true])
            ->assertExitCode(4);
    }

    #[Test]
    public function without_flag_returns_success_even_if_empty(): void
    {
        $this->artisan('app:compute-palmares')
            ->assertExitCode(0);
    }

    #[Test]
    public function processes_pending_players_then_continues(): void
    {
        DB::table('players')->insert([
            'etf2l_id' => 70031,
            'name' => 'kaptain',
            'country' => 'European',
        ]);

        Http::fake([
            '*/player/70031/results*' => Http::response([
                'current_page' => 1, 'data' => [], 'last_page' => 1, 'total' => 0,
            ]),
        ]);

        $this->artisan('app:compute-palmares', ['--exit-on-empty' => true])
            ->assertExitCode(0);
    }
}
