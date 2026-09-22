<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PlayersRepository;
use App\Models\TeamsRepository;
use App\Services\Etf2l\Etf2lApiClient;
use App\Services\Palmares\RosterHarvester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RosterHarvesterTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function harvests_rosters_and_transfers_into_candidates(): void
    {
        DB::table('teams')->insert([
            ['etf2l_team_id' => 32593, 'name' => 'Witness Gaming', 'created_at' => now(), 'updated_at' => now()],
        ]);

        Http::fake([
            '*/team/32593/transfers*' => Http::response($this->fixture('team_32593_transfers')),
            '*/team/32593*' => Http::response(['team' => $this->fixture('team_32593')]),
        ]);

        $harvester = new RosterHarvester(
            new Etf2lApiClient('https://api.example.test', 'palmares-test', 0.0, 5),
            new TeamsRepository,
            new PlayersRepository,
        );

        $inserted = $harvester->run();

        $this->assertGreaterThan(0, $inserted);
        $this->assertDatabaseCount('players', $inserted);

        // Le roster actif est bien représenté.
        $this->assertNotNull((new PlayersRepository)->findByEtf2lId(70031));
        $this->assertSame([], $harvester->errors());
    }

    #[Test]
    public function limit_restricts_the_number_of_teams_processed(): void
    {
        foreach ([32593 => 'Witness Gaming', 9999 => 'Autre équipe'] as $teamId => $name) {
            DB::table('teams')->insert([
                'etf2l_team_id' => $teamId,
                'name' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Http::fake([
            '*/team/9999/transfers*' => Http::response($this->fixture('team_32593_transfers')),
            '*/team/9999*' => Http::response(['team' => $this->fixture('team_32593')]),
            '*/team/32593/transfers*' => Http::response(['data' => [], 'last_page' => 1]),
            '*/team/32593*' => Http::response(['team' => ['id' => 32593, 'name' => 'Witness Gaming', 'players' => []]]),
        ]);

        $harvester = new RosterHarvester(
            new Etf2lApiClient('https://api.example.test', 'palmares-test', 0.0, 5),
            new TeamsRepository,
            new PlayersRepository,
        );

        $inserted = $harvester->run(limit: 1);

        // L'ordre ascendant traite l'équipe 9999 en premier : avec limit=1,
        // l'équipe 32593 ne doit jamais être sollicitée.
        $this->assertGreaterThan(0, $inserted);
        $this->assertSame([], $harvester->errors());
        $this->assertNotContains('https://api.example.test/team/32593', Http::recorded()->map(fn ($r) => (string) $r[0]->url())->all());
    }
}
