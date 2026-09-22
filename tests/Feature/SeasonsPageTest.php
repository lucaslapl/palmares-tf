<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeasonsPageTest extends TestCase
{
    use RefreshDatabase;

    private function insertSeason(int $id, string $name, bool $archived = true): int
    {
        return (int) DB::table('seasons')->insertGetId([
            'etf2l_competition_id' => $id,
            'name' => $name,
            'category' => '6v6 Season',
            'format' => '6s',
            'archived' => $archived,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function index_hides_playoff_competitions(): void
    {
        $regular = $this->insertSeason(971, '6v6 Season 50 (Autumn 2025)');
        $this->insertSeason(501, 'Season 27: High Playoffs');
        $this->insertSeason(1044, '6v6 Season 52: Fresh 3rd Place');
        $this->insertSeason(1049, 'Highlander Season 36: Premiership Qualifiers');

        $response = $this->get(route('seasons.index'));

        $response->assertOk();
        $response->assertSee('6v6 Season 50 (Autumn 2025)', false);
        $response->assertDontSee('High Playoffs', false);
        $response->assertDontSee('3rd Place', false);
        $response->assertDontSee('Qualifiers', false);

        $this->assertSame(1, DB::table('seasons')->where('id', $regular)->count());
    }

    #[Test]
    public function index_links_archived_seasons_to_etf2l(): void
    {
        $this->insertSeason(971, '6v6 Season 50 (Autumn 2025)', archived: true);
        $this->insertSeason(1053, '6v6 Season 53 (Autumn 2026)', archived: false);

        $response = $this->get(route('seasons.index'));

        $response->assertOk();
        $response->assertSee('https://etf2l.org/etf2l/archives/971/', false);
        $response->assertDontSee('https://etf2l.org/etf2l/archives/1053/', false);
    }

    #[Test]
    public function show_links_teams_and_archives_to_etf2l(): void
    {
        $season = $this->insertSeason(971, '6v6 Season 50 (Autumn 2025)');

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

        $this->get(route('seasons.show', ['season' => $season]))
            ->assertOk()
            ->assertSee('https://etf2l.org/etf2l/archives/971/', false)
            ->assertSee('https://etf2l.org/teams/32593/', false)
            ->assertSee('https://etf2l.org/images/flags/European.gif', false);
    }

    #[Test]
    public function show_returns_404_for_playoff_competitions(): void
    {
        $regular = $this->insertSeason(971, '6v6 Season 50 (Autumn 2025)');
        $playoff = $this->insertSeason(501, 'Season 27: High Playoffs');

        $this->get(route('seasons.show', ['season' => $regular]))->assertOk();
        $this->get(route('seasons.show', ['season' => $playoff]))->assertNotFound();
    }
}
