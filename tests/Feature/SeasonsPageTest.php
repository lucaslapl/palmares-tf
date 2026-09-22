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
    public function show_returns_404_for_playoff_competitions(): void
    {
        $regular = $this->insertSeason(971, '6v6 Season 50 (Autumn 2025)');
        $playoff = $this->insertSeason(501, 'Season 27: High Playoffs');

        $this->get(route('seasons.show', ['season' => $regular]))->assertOk();
        $this->get(route('seasons.show', ['season' => $playoff]))->assertNotFound();
    }
}
