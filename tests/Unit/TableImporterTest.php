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
}
