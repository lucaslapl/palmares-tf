<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\SeasonsRepository;
use App\Models\TeamsRepository;
use App\Services\Etf2l\Etf2lApiClient;
use App\Services\Palmares\TableImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TableImporterTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function imports_podiums_and_marks_season_ingested(): void
    {
        $fixture = $this->fixture('competition_971_tables');
        $expectedRows = array_sum(array_map('count', $fixture['tables']));

        DB::table('seasons')->insert([
            'etf2l_competition_id' => 971,
            'name' => '6v6 Season 50 (Autumn 2025)',
            'category' => '6v6 Season',
            'format' => '6s',
            'archived' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake(['*/competition/971/tables*' => Http::response($fixture)]);

        $importer = new TableImporter(
            new Etf2lApiClient('https://api.example.test', 'palmares-test', 0.0, 5),
            new SeasonsRepository,
            new TeamsRepository,
        );

        $count = $importer->run();

        $this->assertSame($expectedRows, $count);
        $this->assertDatabaseCount('season_teams', $expectedRows);

        // Witness Gaming termine 1er en Premiership → or.
        $row = DB::table('season_teams')
            ->join('teams', 'teams.id', '=', 'season_teams.team_id')
            ->where('teams.etf2l_team_id', 32593)
            ->select('season_teams.*')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->ach);
        $this->assertSame('gold', $row->medal);
        $this->assertSame('Premiership', $row->division_name);

        // Les équipes classées sont enregistrées pour la récolte de joueurs.
        $this->assertDatabaseHas('teams', ['etf2l_team_id' => 32593, 'name' => 'Witness Gaming']);

        // La saison est marquée ingérée (elle sort du pending au prochain run).
        $season = (new SeasonsRepository)->findByCompetitionId(971);
        $this->assertNotNull($season->ingested_at);
    }

    #[Test]
    public function isolated_season_failure_does_not_stop_the_batch(): void
    {
        // Échec rapide (1 seule tentative) pour rester dans un temps de test court.
        config()->set('palmares.etf2l.max_attempts', 1);
        config()->set('palmares.etf2l.backoffs', [0]);

        foreach ([971 => '6v6 Season 50 (Autumn 2025)', 999 => 'Saison bidon'] as $comp => $name) {
            DB::table('seasons')->insert([
                'etf2l_competition_id' => $comp,
                'name' => $name,
                'category' => '6v6 Season',
                'format' => '6s',
                'archived' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Http::fake([
            '*/competition/971/tables*' => Http::response($this->fixture('competition_971_tables')),
            // Panne isolée sur la comp 999 : 500 persistante après les retries.
            '*/competition/999/tables*' => Http::response('', 500),
        ]);

        $importer = new TableImporter(
            new Etf2lApiClient('https://api.example.test', 'palmares-test', 0.0, 5),
            new SeasonsRepository,
            new TeamsRepository,
        );

        $count = $importer->run();

        // La bonne saison est ingérée ; la défaillante reste pending + signalée.
        $this->assertGreaterThan(0, $count);
        $this->assertNotNull((new SeasonsRepository)->findByCompetitionId(971)->ingested_at);
        $this->assertNull((new SeasonsRepository)->findByCompetitionId(999)->ingested_at);
        $this->assertCount(1, $importer->errors());
    }

    #[Test]
    public function limit_restricts_the_number_of_seasons_processed(): void
    {
        foreach ([971 => '6v6 Season 50 (Autumn 2025)', 999 => 'Saison suivante'] as $comp => $name) {
            DB::table('seasons')->insert([
                'etf2l_competition_id' => $comp,
                'name' => $name,
                'category' => '6v6 Season',
                'format' => '6s',
                'archived' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Http::fake([
            '*/competition/971/tables*' => Http::response($this->fixture('competition_971_tables')),
            '*/competition/999/tables*' => Http::response($this->fixture('competition_971_tables')),
        ]);

        $importer = new TableImporter(
            new Etf2lApiClient('https://api.example.test', 'palmares-test', 0.0, 5),
            new SeasonsRepository,
            new TeamsRepository,
        );

        $count = $importer->run(limit: 1);

        // pendingTables() trie par compétition DESC : seule une saison est
        // ingérée, l'autre reste en attente.
        $this->assertGreaterThan(0, $count);
        $ingested = (new SeasonsRepository)->listAll();
        $this->assertCount(1, array_filter($ingested, static fn (object $s): bool => $s->ingested_at !== null));
        $this->assertCount(1, array_filter($ingested, static fn (object $s): bool => $s->ingested_at === null));
    }

    protected function tearDown(): void
    {
        config()->set('palmares.etf2l.max_attempts', 5);
        config()->set('palmares.etf2l.backoffs', [0, 2, 10, 30, 60]);

        parent::tearDown();
    }
}
