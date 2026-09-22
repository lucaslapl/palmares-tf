<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\SeasonsRepository;
use App\Services\Etf2l\Etf2lApiClient;
use App\Services\Palmares\SeasonImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeasonImporterTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function imports_season_competitions_only(): void
    {
        $fixture = $this->fixture('competition_list_page1');
        $expected = count(array_filter(
            $fixture['competitions']['data'],
            static fn (array $c): bool => in_array($c['category'], config('palmares.categories.seasons'), true),
        ));
        $this->assertGreaterThan(0, $expected);

        // Fixture servie sur une seule page : on masque la pagination réelle
        // du payload pour que l'importer s'arrête après la première page.
        $fixture['competitions']['last_page'] = 1;
        $fixture['competitions']['next_page_url'] = null;
        $fixture['competitions']['current_page'] = 1;

        Http::fake(['*/competition/list*' => Http::response($fixture)]);

        $importer = new SeasonImporter(
            new Etf2lApiClient('https://api.example.test', 'palmares-test', 0.0, 5),
            new SeasonsRepository,
        );

        $count = $importer->run();

        $this->assertSame($expected, $count);
        $this->assertDatabaseCount('seasons', $expected);

        $season = (new SeasonsRepository)->findByCompetitionId((int) $fixture['competitions']['data'][0]['id']);

        $this->assertNotNull($season);
        $this->assertSame('6v6 Season', $season->category);
        $this->assertSame('6s', $season->format);
    }
}
