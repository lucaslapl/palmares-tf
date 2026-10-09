<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\SeasonGroupRepository;
use App\Models\SeasonsRepository;
use App\Services\Etf2l\Etf2lApiClient;
use App\Services\Palmares\SeasonImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            new SeasonGroupRepository,
        );

        $count = $importer->run();

        $this->assertSame($expected, $count);
        $this->assertDatabaseCount('seasons', $expected);

        $season = (new SeasonsRepository)->findByCompetitionId((int) $fixture['competitions']['data'][0]['id']);

        $this->assertNotNull($season);
        $this->assertSame('6v6 Season', $season->category);
        $this->assertSame('6s', $season->format);
    }

    #[Test]
    public function groups_divisions_of_a_season_under_one_season_group(): void
    {
        $fixture = $this->fixture('competition_list_page1');
        $fixture['competitions']['last_page'] = 1;
        $fixture['competitions']['next_page_url'] = null;
        $fixture['competitions']['current_page'] = 1;

        Http::fake(['*/competition/list*' => Http::response($fixture)]);

        $importer = new SeasonImporter(
            new Etf2lApiClient('https://api.example.test', 'palmares-test', 0.0, 5),
            new SeasonsRepository,
            new SeasonGroupRepository,
        );

        $importer->run();

        // Chaque saison logique (« 6v6 Season 52 », « Highlander Season 36 »)
        // a exactement un groupe, et toutes ses divisions y sont rattachées.
        $ungrouped = DB::table('seasons')->whereNull('season_group_id')->count();
        $this->assertSame(0, $ungrouped);

        $season52 = DB::table('season_groups')
            ->where('format', '6s')
            ->where('season_number', 52)
            ->first();

        $this->assertNotNull($season52);
        $this->assertSame('6v6 Season 52', $season52->name);

        $divisions = DB::table('seasons')->where('season_group_id', $season52->id)->pluck('name')->all();
        $this->assertNotEmpty($divisions);
        foreach ($divisions as $name) {
            $this->assertStringContainsString('Season 52', $name);
        }
    }

    #[Test]
    public function limit_stops_import_after_the_requested_count(): void
    {
        $fixture = $this->fixture('competition_list_page1');
        $fixture['competitions']['last_page'] = 1;
        $fixture['competitions']['next_page_url'] = null;
        $fixture['competitions']['current_page'] = 1;

        Http::fake(['*/competition/list*' => Http::response($fixture)]);

        $importer = new SeasonImporter(
            new Etf2lApiClient('https://api.example.test', 'palmares-test', 0.0, 5),
            new SeasonsRepository,
            new SeasonGroupRepository,
        );

        $count = $importer->run(limit: 1);

        $this->assertSame(1, $count);
        $this->assertDatabaseCount('seasons', 1);
    }

    #[Test]
    public function highlander_competition_with_errored_type_is_classified_9v9(): void
    {
        // Certaines compétitions Highlander ont un type "6v6" erroné dans
        // l'API (ex. Highlander Season 32) : la catégorie prime sur le type.
        (new SeasonsRepository)->insertOrIgnoreCompetition([
            'id' => 926,
            'name' => 'Highlander Season 32 (Summer 2024): Premiership',
            'category' => 'Highlander Season',
            'type' => '6v6',
            'archived' => true,
        ]);

        $season = (new SeasonsRepository)->findByCompetitionId(926);

        $this->assertNotNull($season);
        $this->assertSame('9v9', $season->format);
    }
}
