<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SeasonsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeasonsPageTest extends TestCase
{
    use RefreshDatabase;

    private function insertSeason(int $id, string $name, bool $archived = true, ?int $groupId = null): int
    {
        $seasonId = (int) DB::table('seasons')->insertGetId([
            'etf2l_competition_id' => $id,
            'name' => $name,
            'category' => '6v6 Season',
            'format' => '6s',
            'season_group_id' => $groupId,
            'archived' => $archived,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $seasonId;
    }

    private function insertSeasonGroup(int $seasonNumber, string $format = '6s'): int
    {
        return (int) DB::table('season_groups')->insertGetId([
            'format' => $format,
            'season_number' => $seasonNumber,
            'name' => SeasonsRepository::seasonGroupName($format, $seasonNumber),
            'min_competition_id' => 0,
            'max_competition_id' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function index_hides_playoff_competitions(): void
    {
        // Créer un groupe de saison pour la saison régulière
        $groupId = $this->insertSeasonGroup(50, '6s');

        // Créer des saisons (compétitions)
        $regular = $this->insertSeason(971, '6v6 Season 50 (Autumn 2025)', true, $groupId);
        $this->insertSeason(501, 'Season 27: High Playoffs');
        $this->insertSeason(1044, '6v6 Season 52: Fresh 3rd Place');
        $this->insertSeason(1049, 'Highlander Season 36: Premiership Qualifiers');

        $response = $this->get(route('seasons.index'));

        $response->assertOk();
        // Maintenant on affiche le nom du groupe de saison
        $response->assertSee('6v6 Season 50', false);
        $response->assertDontSee('High Playoffs', false);
        $response->assertDontSee('3rd Place', false);
        $response->assertDontSee('Qualifiers', false);

        $this->assertSame(1, DB::table('seasons')->where('id', $regular)->count());
    }

    #[Test]
    public function index_links_archived_seasons_to_etf2l(): void
    {
        // Créer des groupes de saisons
        $group50 = $this->insertSeasonGroup(50, '6s');
        $group53 = $this->insertSeasonGroup(53, '6s');

        $this->insertSeason(971, '6v6 Season 50 (Autumn 2025)', true, $group50);
        $this->insertSeason(1053, '6v6 Season 53 (Autumn 2026)', false, $group53);

        $response = $this->get(route('seasons.index'));

        $response->assertOk();
        $response->assertSee('https://etf2l.org/etf2l/archives/971/', false);
        $response->assertDontSee('https://etf2l.org/etf2l/archives/1053/', false);
    }

    #[Test]
    public function show_links_teams_and_archives_to_etf2l(): void
    {
        // Créer un groupe de saison
        $groupId = $this->insertSeasonGroup(50, '6s');
        $season = $this->insertSeason(971, '6v6 Season 50 (Autumn 2025)', true, $groupId);

        $teamId = (int) DB::table('teams')->insertGetId([
            'etf2l_team_id' => 32593,
            'name' => 'Witness Gaming',
            'country' => 'European',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('season_teams')->insert([
            'season_id' => $season,
            'team_id' => $teamId,
            'division_name' => 'Premiership',
            'ach' => 1,
            'medal' => 'gold',
        ]);

        // Avec les groupes de saisons, on passe l'ID du groupe
        $this->get(route('seasons.show', ['season' => $groupId]))
            ->assertOk()
            ->assertSee('https://etf2l.org/etf2l/archives/971/', false)
            ->assertSee('https://etf2l.org/teams/32593/', false)
            ->assertSee('https://etf2l.org/images/flags/European.gif', false);
    }

    #[Test]
    public function show_returns_404_for_playoff_competitions(): void
    {
        // Créer un groupe de saison pour la saison régulière
        $groupId = $this->insertSeasonGroup(50, '6s');

        $regular = $this->insertSeason(971, '6v6 Season 50 (Autumn 2025)', true, $groupId);
        $playoff = $this->insertSeason(501, 'Season 27: High Playoffs');

        // Avec les groupes de saisons, on passe l'ID du groupe
        $this->get(route('seasons.show', ['season' => $groupId]))->assertOk();
        $this->get(route('seasons.show', ['season' => $playoff]))->assertNotFound();
    }

    #[Test]
    public function show_orders_divisions_by_prestige(): void
    {
        // Créer un groupe de saison
        $groupId = $this->insertSeasonGroup(50, '6s');
        $season = $this->insertSeason(971, '6v6 Season 50 (Autumn 2025)', true, $groupId);

        // Insertion volontairement dans le désordre : l'affichage doit
        // respecter Premiership > High > numérotées (croissant) > Mid > Low > Open.
        $order = ['Open', 'Low', 'Mid', 'Division 3', 'Division 2', 'High', 'Premiership'];

        foreach ($order as $i => $division) {
            $teamId = (int) DB::table('teams')->insertGetId([
                'etf2l_team_id' => 40000 + $i,
                'name' => 'Team '.$division,
                'country' => 'European',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('season_teams')->insert([
                'season_id' => $season,
                'team_id' => $teamId,
                'division_name' => $division,
                'ach' => $i + 1,
                'medal' => null,
            ]);
        }

        // Avec les groupes de saisons, on passe l'ID du groupe
        $this->get(route('seasons.show', ['season' => $groupId]))
            ->assertOk()
            ->assertSeeInOrder([
                '<h2>Premiership</h2>',
                '<h2>High</h2>',
                '<h2>Division 2</h2>',
                '<h2>Division 3</h2>',
                '<h2>Mid</h2>',
                '<h2>Low</h2>',
                '<h2>Open</h2>',
            ]);
    }

    #[Test]
    public function index_groups_divisions_of_the_same_season_into_one_entry(): void
    {
        // Une saison logique (Season 52) avec plusieurs divisions, chacune
        // étant une compétition ETF2L distincte.
        $groupId = $this->insertSeasonGroup(52, '6s');
        $this->insertSeason(1040, '6v6 Season 52 (Summer 2026): Division 1', true, $groupId);
        $this->insertSeason(1041, '6v6 Season 52 (Summer 2026): Division 2', true, $groupId);
        $this->insertSeason(1042, '6v6 Season 52 (Summer 2026): Division 3', true, $groupId);

        $response = $this->get(route('seasons.index'));

        $response->assertOk();
        // Une seule entrée pour la saison, nom du groupe affiché.
        $this->assertSame(
            1,
            substr_count((string) $response->getContent(), '6v6 Season 52'),
        );
        $response->assertSee('6v6 Season 52', false);
        $response->assertDontSee('6v6 Season 52 (Summer 2026): Division 1', false);
        // Le nombre de divisions rattachées est affiché.
        $response->assertSee('(3 divs)', false);
    }

    #[Test]
    public function show_displays_divisions_from_all_competitions_of_the_group(): void
    {
        $groupId = $this->insertSeasonGroup(52, '6s');
        $division1 = $this->insertSeason(1040, '6v6 Season 52 (Summer 2026): Division 1', true, $groupId);
        $division2 = $this->insertSeason(1041, '6v6 Season 52 (Summer 2026): Division 2', true, $groupId);

        foreach ([$division1 => 'Premiership', $division2 => 'Division 2'] as $seasonId => $division) {
            $teamId = (int) DB::table('teams')->insertGetId([
                'etf2l_team_id' => 50000 + $seasonId,
                'name' => 'Team '.$division,
                'country' => 'European',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('season_teams')->insert([
                'season_id' => $seasonId,
                'team_id' => $teamId,
                'division_name' => $division,
                'ach' => 1,
                'medal' => 'gold',
            ]);
        }

        $this->get(route('seasons.show', ['season' => $groupId]))
            ->assertOk()
            ->assertSee('<h2>Premiership</h2>', false)
            ->assertSee('<h2>Division 2</h2>', false)
            ->assertSee('Team Premiership', false)
            ->assertSee('Team Division 2', false)
            ->assertSee('Includes 2 divisions', false);
    }
}
