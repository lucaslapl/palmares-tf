<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PalmaresRepository;
use App\Models\PlayersRepository;
use App\Services\Etf2l\Etf2lApiClient;
use App\Services\Palmares\ComputePalmaresService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ComputePalmaresServiceTest extends TestCase
{
    use RefreshDatabase;

    private const PLAYER = 70031;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('players')->insert([
            'etf2l_id' => self::PLAYER,
            'name' => 'kaptain',
            'country' => 'European',
        ]);
    }

    #[Test]
    public function detects_gold_medal_from_final_table(): void
    {
        Http::fake([
            '*/player/'.self::PLAYER.'/results*' => Http::response($this->season50Results()),
            '*/competition/971/tables*' => Http::response($this->fixture('competition_971_tables')),
            '*/competition/*' => Http::response(['tables' => []]),
        ]);

        $entries = $this->service()->computeForPlayer(self::PLAYER);

        $this->assertCount(1, $entries);
        $entry = $entries[0];

        $this->assertSame(971, (int) $entry['competition_id']);
        $this->assertSame(1, (int) $entry['placement']);
        $this->assertSame('gold', $entry['medal']);
        $this->assertSame('6s', $entry['format']);
        $this->assertSame('Witness Gaming', $entry['team_name']);
        $this->assertSame('Premiership', $entry['division_name']);

        // Stockage + joueur marqué calculé.
        $this->assertDatabaseHas('palmares', ['medal' => 'gold', 'placement' => 1]);
        $this->assertNotNull((new PlayersRepository)->findByEtf2lId(self::PLAYER)->computed_at);
    }

    #[Test]
    public function merges_regular_season_and_playoffs_into_single_record(): void
    {
        $results = $this->season50Results();
        $data = $results['data'];

        // Playoffs distincts de la même saison : nom partageant la clé de saison.
        $data[] = $this->match(
            competitionId: 999,
            competitionName: '6v6 Season 50 (Autumn 2025): Premiership Playoffs',
            round: 'Grand Final',
            r1: 3,
            r2: 1,
            time: 1700000100,
        );
        $results['data'] = $data;
        $results['total'] = count($data);

        Http::fake([
            '*/player/'.self::PLAYER.'/results*' => Http::response($results),
            '*/competition/971/tables*' => Http::response($this->fixture('competition_971_tables')),
            '*/competition/*' => Http::response(['tables' => []]),
        ]);

        $entries = $this->service()->computeForPlayer(self::PLAYER);

        // Une seule entrée : la médaille de la saison + le round de playoffs.
        $this->assertCount(1, $entries);
        $entry = $entries[0];

        $this->assertSame('gold', $entry['medal']);
        $this->assertSame('Grand Final', $entry['playoff_round']);
        $this->assertTrue($entry['won_playoff']);
    }

    #[Test]
    public function empty_results_mark_player_computed_without_entries(): void
    {
        Http::fake([
            '*/player/'.self::PLAYER.'/results*' => Http::response([
                'current_page' => 1, 'data' => [], 'last_page' => 1, 'total' => 0,
            ]),
        ]);

        $entries = $this->service()->computeForPlayer(self::PLAYER);

        $this->assertSame([], $entries);
        $this->assertDatabaseMissing('palmares', ['player_id' => 1]);
        $this->assertNotNull((new PlayersRepository)->findByEtf2lId(self::PLAYER)->computed_at);
    }

    #[Test]
    public function reports_when_players_are_pending_or_not(): void
    {
        // Un joueur inséré dans setUp() sans computed_at → en attente.
        $this->assertTrue($this->service()->hasPendingPlayers());

        // Une fois calculé (maison éloignée du seuil de staleness) → plus rien.
        DB::table('players')->update(['computed_at' => time()]);

        $this->assertFalse($this->service()->hasPendingPlayers());
    }

    private function service(): ComputePalmaresService
    {
        return new ComputePalmaresService(
            new Etf2lApiClient('https://api.example.test', 'palmares-test', 0.0, 5),
            new PlayersRepository,
            new PalmaresRepository,
        );
    }

    /**
     * Trois matches de saison régulière de la S50 (comp 971, Premiership).
     *
     * @return array<string, mixed>
     */
    private function season50Results(): array
    {
        $matches = [];
        for ($week = 1; $week <= 3; $week++) {
            $matches[] = $this->match(971, '6v6 Season 50 (Autumn 2025)', '', 4, 2, 1700000000 + $week, $week);
        }

        return [
            'current_page' => 1,
            'data' => $matches,
            'last_page' => 1,
            'total' => count($matches),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function match(
        int $competitionId,
        string $competitionName,
        string $round,
        int $r1,
        int $r2,
        int $time,
        ?int $week = null,
    ): array {
        return [
            'clan1' => [
                'country' => 'European', 'drop' => false, 'id' => 32593,
                'name' => 'Witness Gaming', 'steam' => ['avatar' => ''],
                'was_in_team' => true, 'url' => '',
            ],
            'clan2' => [
                'country' => '', 'drop' => false, 'id' => 40000 + $week,
                'name' => 'Opponent '.$week, 'steam' => ['avatar' => ''],
                'was_in_team' => false, 'url' => '',
            ],
            'competition' => [
                'category' => '6v6 Season', 'id' => $competitionId,
                'name' => $competitionName, 'type' => '6v6', 'url' => '',
            ],
            'division' => ['name' => 'Premiership', 'tier' => 0],
            'round' => $round,
            'week' => $week,
            'result' => 'win',
            'r1' => $r1,
            'r2' => $r2,
            'time' => $time,
        ];
    }
}
