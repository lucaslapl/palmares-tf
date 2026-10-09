<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\StatsRepository;
use App\Services\Palmares\StatsBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class StatsBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useIsolatedDataDir();

        // Un stats.json laissé par un test précédent fausserait les lectures :
        // chaque test repart d'une base (et d'un fichier) vierge.
        @unlink(palmares_data_path('stats.json'));

        // Joueurs : 1 et 2 en 6v6, 3 en 9v9 uniquement.
        DB::table('players')->insert([
            ['etf2l_id' => 1, 'name' => 'Alpha'],
            ['etf2l_id' => 2, 'name' => 'Beta'],
            ['etf2l_id' => 3, 'name' => 'Gamma'],
        ]);

        // Saison 6v6 10 découpée en deux divisions + playoffs satellites,
        // rattachés à la même saison logique « Season 10 ».
        DB::table('seasons')->insert([
            ['id' => 1, 'etf2l_competition_id' => 100, 'name' => 'Season 10 (Autumn 2010): Division 1', 'category' => '6v6 Season', 'format' => '6s', 'archived' => true],
            ['id' => 2, 'etf2l_competition_id' => 101, 'name' => 'Season 10 (Autumn 2010): Division 2', 'category' => '6v6 Season', 'format' => '6s', 'archived' => true],
            ['id' => 3, 'etf2l_competition_id' => 102, 'name' => 'Season 10 (Autumn 2010): Division 1 Playoffs', 'category' => '6v6 Season', 'format' => '6s', 'archived' => true],
            ['id' => 4, 'etf2l_competition_id' => 103, 'name' => 'Season 11 (Spring 2011)', 'category' => '6v6 Season', 'format' => '6s', 'archived' => true],
            ['id' => 5, 'etf2l_competition_id' => 104, 'name' => 'Highlander Season 2 (Autumn 2011)', 'category' => 'Highlander Season', 'format' => '9v9', 'archived' => true],
        ]);

        // Équipes classées : 2 en division 1, 3 en division 2 de la saison 10.
        for ($teamId = 1; $teamId <= 5; $teamId++) {
            DB::table('teams')->insert(['etf2l_team_id' => $teamId, 'name' => "Team {$teamId}"]);
        }

        DB::table('season_teams')->insert([
            ['season_id' => 1, 'team_id' => 1, 'division_name' => 'Division 1', 'ach' => 1, 'medal' => 'gold'],
            ['season_id' => 1, 'team_id' => 2, 'division_name' => 'Division 1', 'ach' => null, 'medal' => null],
            ['season_id' => 2, 'team_id' => 3, 'division_name' => 'Division 2', 'ach' => null, 'medal' => null],
            ['season_id' => 2, 'team_id' => 4, 'division_name' => 'Division 2', 'ach' => null, 'medal' => null],
            ['season_id' => 2, 'team_id' => 5, 'division_name' => 'Division 2', 'ach' => null, 'medal' => null],
        ]);

        // Palmarès : les playoffs satellites (saison 3) reforcent la même
        // saison logique ; la Nations Cup (season_id NULL) est hors ligue.
        DB::table('palmares')->insert([
            ['player_id' => 1, 'season_id' => 1, 'competition_id' => 100, 'format' => '6s', 'competition_name' => 'Season 10 (Autumn 2010): Division 1', 'placement' => 1, 'medal' => 'gold', 'playoff_round' => null, 'season_time' => 1289043200],
            ['player_id' => 2, 'season_id' => 3, 'competition_id' => 102, 'format' => '6s', 'competition_name' => 'Season 10 (Autumn 2010): Division 1 Playoffs', 'placement' => null, 'medal' => null, 'playoff_round' => 'Quarter-final', 'season_time' => 1290000000],
            ['player_id' => 1, 'season_id' => 4, 'competition_id' => 103, 'format' => '6s', 'competition_name' => 'Season 11 (Spring 2011)', 'placement' => 1, 'medal' => 'gold', 'playoff_round' => null, 'season_time' => 1302000000],
            ['player_id' => 2, 'season_id' => 4, 'competition_id' => 103, 'format' => '6s', 'competition_name' => 'Season 11 (Spring 2011)', 'placement' => 2, 'medal' => 'silver', 'playoff_round' => null, 'season_time' => 1302000000],
            ['player_id' => 3, 'season_id' => 5, 'competition_id' => 104, 'format' => '9v9', 'competition_name' => 'Highlander Season 2 (Autumn 2011)', 'placement' => 1, 'medal' => 'gold', 'playoff_round' => null, 'season_time' => 1317000000],
            ['player_id' => 3, 'season_id' => null, 'competition_id' => 200, 'format' => '9v9', 'competition_name' => 'Nations Cup #1', 'placement' => null, 'medal' => null, 'playoff_round' => 'Final', 'season_time' => 1318000000],
        ]);
    }

    #[Test]
    public function groups_by_logical_season_and_dates_them(): void
    {
        $payload = (new StatsBuilder(new StatsRepository))->rebuild();

        $seasons6 = $payload['seasons']['6s'];
        $this->assertCount(2, $seasons6);

        // Season 10 : deux joueurs (playoffs inclus), 5 équipes sur les deux
        // divisions, fin datée par le match le plus récent.
        $this->assertSame('Season 10', $seasons6[0]['name']);
        $this->assertSame(2010, $seasons6[0]['year']);
        $this->assertSame(2, $seasons6[0]['players']);
        $this->assertSame(5, $seasons6[0]['teams']);
        $this->assertSame(2, $seasons6[0]['divisions']);
        $this->assertSame(1290000000, $seasons6[0]['end_time']);

        $this->assertSame('Season 11', $seasons6[1]['name']);
        $this->assertSame(2011, $seasons6[1]['year']);
    }

    #[Test]
    public function counts_new_and_returning_players_per_format(): void
    {
        $payload = (new StatsBuilder(new StatsRepository))->rebuild();

        [$season10, $season11] = $payload['seasons']['6s'];

        // Saison 10 : Alpha et Beta sont tous deux nouveaux.
        $this->assertSame(2, $season10['new_players']);
        $this->assertSame(0, $season10['returning_players']);

        // Saison 11 : les deux reviennent.
        $this->assertSame(0, $season11['new_players']);
        $this->assertSame(2, $season11['returning_players']);
    }

    #[Test]
    public function counts_competitions_per_year_including_non_league(): void
    {
        $payload = (new StatsBuilder(new StatsRepository))->rebuild();

        // 2010 : une saison 6v6. 2011 : une saison 6v6, une 9v9, une Nations Cup.
        $this->assertSame(1, $payload['years'][0]['seasons_6s']);
        $this->assertSame(2010, $payload['years'][0]['year']);
        $this->assertSame(0, $payload['years'][0]['other']);

        $this->assertSame(2011, $payload['years'][1]['year']);
        $this->assertSame(1, $payload['years'][1]['seasons_6s']);
        $this->assertSame(1, $payload['years'][1]['seasons_9v9']);
        $this->assertSame(1, $payload['years'][1]['other']);
    }

    #[Test]
    public function read_rebuilds_missing_json_then_serves_it(): void
    {
        $builder = new StatsBuilder(new StatsRepository);

        // Premier accès : stats.json n'existe pas encore, la lecture le génère.
        $payload = $builder->read();
        $this->assertGreaterThan(0, $payload['generated_at']);
        $this->assertFileExists(palmares_data_path('stats.json'));

        // Deuxième accès : le fichier frais est servi sans recalcul.
        $this->assertSame($payload, $builder->read());
    }
}
