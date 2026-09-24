<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PalmaresRepository;
use App\Models\PlayersRepository;
use App\Services\Palmares\LeaderboardBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class LeaderboardBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useIsolatedDataDir();

        // Joueur A : or (6s) + argent (9v9) → 5 pts, 1 or, 1 argent, ban actif.
        DB::table('players')->insert(['etf2l_id' => 1, 'name' => 'Alpha Player', 'country' => 'AU', 'ban_until' => time() + 86400]);
        DB::table('players')->insert(['etf2l_id' => 2, 'name' => 'Beta Player', 'country' => 'GB']);
        // Joueur C : ban expiré → ne doit pas être signalé.
        DB::table('players')->insert(['etf2l_id' => 3, 'name' => 'Gamma Player', 'country' => 'FR', 'ban_until' => time() - 86400]);

        $this->award(1, player: 1, format: '6s', medal: 'gold', placement: 1);
        $this->award(2, player: 1, format: '9v9', medal: 'silver', placement: 2);
        // Joueur B : deux ors (6s) → 6 pts, 2 ors → doit passer devant A.
        $this->award(3, player: 2, format: '6s', medal: 'gold', placement: 1);
        $this->award(4, player: 2, format: '6s', medal: 'gold', placement: 1);
    }

    #[Test]
    public function rebuilds_sorted_leaderboards(): void
    {
        $stats = (new LeaderboardBuilder(new PalmaresRepository, new PlayersRepository))->rebuildAll();

        $this->assertEquals(['leaderboard_all' => 2, 'leaderboard_6v6' => 2, 'leaderboard_9v9' => 1, 'players_index' => 3], $stats);

        $all = $this->readJson('leaderboard.json');

        $this->assertCount(2, $all['players']);
        $this->assertSame(2, $all['players'][0]['etf2l_id']); // Beta (6 pts) devant Alpha (5 pts)…
        $this->assertSame(1, $all['players'][1]['etf2l_id']);
        $this->assertSame(6, $all['players'][0]['points']);
        $this->assertSame(2, $all['players'][0]['golds']);
        // Ban actif : Beta sans ban, Alpha banni.
        $this->assertFalse($all['players'][0]['banned']);
        $this->assertTrue($all['players'][1]['banned']);
        $this->assertGreaterThan(time(), $all['players'][1]['ban_until']);

        $s6 = $this->readJson('leaderboard-6s.json');
        $this->assertCount(2, $s6['players']);
        $this->assertSame(2, $s6['players'][0]['etf2l_id']);

        $s9 = $this->readJson('leaderboard-9v9.json');
        $this->assertCount(1, $s9['players']);
        $this->assertSame(1, $s9['players'][0]['etf2l_id']);
        $this->assertSame(1, $s9['players'][0]['silvers']);
    }

    #[Test]
    public function indexes_players_for_search(): void
    {
        (new LeaderboardBuilder(new PalmaresRepository, new PlayersRepository))->rebuildPlayersIndex();

        $index = $this->readJson('players-index.json');

        $names = array_map(static fn (array $p): string => $p['name'], $index['players']);
        $this->assertContains('Alpha Player', $names);
        $this->assertContains('Beta Player', $names);
        $this->assertContains('Gamma Player', $names);
    }

    #[Test]
    public function does_not_flag_expired_bans(): void
    {
        // Gamma a un ban expiré mais aucune médaille : on lui en attribue une
        // pour vérifier qu'il apparaît au leaderboard sans être marqué banni.
        DB::table('palmares')->insert([
            'player_id' => 3, 'competition_id' => 5, 'format' => '6s',
            'competition_name' => 'Season 5', 'team_name' => 'Gamma Team',
            'division_name' => 'Premiership', 'placement' => 3, 'medal' => 'bronze',
            'season_time' => 1700000005,
        ]);

        (new LeaderboardBuilder(new PalmaresRepository, new PlayersRepository))->rebuildLeaderboard('6s');

        $s6 = $this->readJson('leaderboard-6s.json');
        $gamma = array_values(array_filter($s6['players'], static fn (array $p): bool => $p['etf2l_id'] === 3));

        $this->assertCount(1, $gamma);
        $this->assertFalse($gamma[0]['banned']);
        $this->assertLessThan(time(), $gamma[0]['ban_until']);
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $file): array
    {
        $payload = json_decode((string) file_get_contents(palmares_data_path($file)), true);

        return is_array($payload) ? $payload : [];
    }

    private function award(int $competitionId, int $player, string $format, string $medal, int $placement): void
    {
        DB::table('palmares')->insert([
            'player_id' => $player,
            'competition_id' => $competitionId,
            'format' => $format,
            'competition_name' => 'Season '.$competitionId,
            'team_name' => 'Team \('.$player.'\)',
            'division_name' => 'Premiership',
            'placement' => $placement,
            'medal' => $medal,
            'season_time' => 1700000000 + $competitionId,
        ]);
    }
}
