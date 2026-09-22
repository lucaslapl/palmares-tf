<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PlayersRepository;
use App\Models\SeasonsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BackfillCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useIsolatedDataDir();
        @mkdir(palmares_data_path(), 0777, true);

        // Tests rapides : pas de throttle ni de retries longs.
        config()->set('palmares.etf2l.request_delay_s', 0.0);
        config()->set('palmares.etf2l.max_attempts', 1);
        config()->set('palmares.etf2l.backoffs', [0]);
    }

    #[Test]
    public function up_to_date_pipeline_exits_with_code_4(): void
    {
        DB::table('seasons')->insert([
            'etf2l_competition_id' => 971,
            'name' => '6v6 Season 50 (Autumn 2025)',
            'category' => '6v6 Season',
            'format' => '6s',
            'archived' => true,
            'ingested_at' => time(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Aucun appel réseau attendu : tout est déjà consommé.
        Http::fake([]);

        $this->artisan('app:backfill')
            ->assertExitCode(4);

        Http::assertSentCount(0);
        $this->assertFalse((new SeasonsRepository)->countPendingArchived() > 0);
    }

    #[Test]
    public function exits_immediately_when_lock_is_held(): void
    {
        $lock = fopen(palmares_data_path('backfill.lock'), 'c');
        flock($lock, LOCK_EX);

        DB::table('seasons')->insert([
            'etf2l_competition_id' => 971,
            'name' => '6v6 Season 50 (Autumn 2025)',
            'category' => '6v6 Season',
            'format' => '6s',
            'archived' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake([]);

        $this->artisan('app:backfill')
            ->assertExitCode(0);

        Http::assertSentCount(0);
        $this->assertSame(1, (new SeasonsRepository)->countPendingArchived());

        flock($lock, LOCK_UN);
        fclose($lock);
    }

    #[Test]
    public function interrupted_step_stops_tranche_and_leaves_work_pending(): void
    {
        // La liste des compétitions est indisponible (500 persistant) : la
        // tranche s'interrompt sans mourir, le travail reste en attente.
        DB::table('seasons')->insert([
            'etf2l_competition_id' => 971,
            'name' => '6v6 Season 50 (Autumn 2025)',
            'category' => '6v6 Season',
            'format' => '6s',
            'archived' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake([
            '*/competition/list*' => Http::response('', 500),
        ]);

        $this->artisan('app:backfill')
            ->assertExitCode(0);

        $this->assertSame(1, (new SeasonsRepository)->countPendingArchived());
    }

    #[Test]
    public function full_pipeline_processes_and_terminates(): void
    {
        // Une saison archivée connue : la chaîne complète va tourner
        // (saisons → tables → rosters → palmarès → JSON) puis se terminer.
        DB::table('seasons')->insert([
            'etf2l_competition_id' => 971,
            'name' => '6v6 Season 50 (Autumn 2025)',
            'category' => '6v6 Season',
            'format' => '6s',
            'archived' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake([
            // Une seule saison, archivée, sur une seule page.
            '*/competition/list*' => Http::response([
                'competitions' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'data' => [[
                        'category' => '6v6 Season',
                        'id' => 971,
                        'name' => '6v6 Season 50 (Autumn 2025)',
                        'archived' => true,
                        'type' => '6v6',
                    ]],
                ],
            ]),
            // La table de classement de la S50 (fixture réelle).
            '*/competition/971/tables*' => Http::response($this->fixture('competition_971_tables')),
            // Rosters + transferts (fixtures réelles, une seule équipe).
            '*/team/*/transfers*' => Http::response($this->fixture('team_32593_transfers')),
            '*/team/*' => Http::response($this->fixture('team_32593')),
            // Chaque joueur récolté n'a aucun résultat exploitable.
            '*/player/*/results*' => Http::response([
                'current_page' => 1, 'data' => [], 'last_page' => 1, 'total' => 0,
            ]),
            '*/competition/*/tables*' => Http::response(['tables' => []]),
        ]);

        $this->artisan('app:backfill', ['--runtime' => 300])
            ->assertExitCode(4);

        // La saison est ingérée, les podiums sont en base, les JSON ont été générés.
        $this->assertSame(109, DB::table('season_teams')->count());
        $this->assertDatabaseHas('season_teams', ['division_name' => 'Fresh', 'medal' => 'gold']);
        $this->assertSame(0, (new SeasonsRepository)->countPendingArchived());
        $this->assertSame(0, (new PlayersRepository)->countUncomputed());
        $this->assertFileExists(palmares_data_path('leaderboard.json'));
        $this->assertFileExists(palmares_data_path('players-index.json'));
    }
}
