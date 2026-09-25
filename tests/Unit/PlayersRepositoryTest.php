<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PlayersRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PlayersRepositoryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function upsert_keeps_the_latest_ban_end_from_api(): void
    {
        $repository = new PlayersRepository;

        // Payload réel de l'API : tableau "bans" {start, end, reason}, null si jamais banni.
        $repository->upsertFromApi([
            'id' => 139191,
            'name' => 'Fidus',
            'country' => 'FR',
            'bans' => [
                ['start' => 1679314388, 'end' => 1742425200, 'reason' => 'Cheating'],
                ['start' => 1763255057, 'end' => 2117574000, 'reason' => 'Doxxing'],
            ],
        ]);

        $row = DB::table('players')->where('etf2l_id', 139191)->first();
        $this->assertSame(2117574000, (int) $row->ban_until);

        // Mise à jour : bans levés → ban_until repasse à null.
        $repository->upsertFromApi([
            'id' => 139191,
            'name' => 'Fidus',
            'country' => 'FR',
            'bans' => null,
        ]);

        $row = DB::table('players')->where('etf2l_id', 139191)->first();
        $this->assertNull($row->ban_until);
    }

    #[Test]
    public function upsert_without_bans_leaves_ban_until_null(): void
    {
        (new PlayersRepository)->upsertFromApi([
            'id' => 70031,
            'name' => 'kaptain',
            'country' => 'Netherlands',
            'steam' => ['id64' => '76561198033727092'],
        ]);

        $row = DB::table('players')->where('etf2l_id', 70031)->first();
        $this->assertNull($row->ban_until);
    }

    #[Test]
    public function reset_computed_reaarms_only_computed_players(): void
    {
        $repository = new PlayersRepository;

        DB::table('players')->insert([
            ['etf2l_id' => 1, 'name' => 'Alpha', 'computed_at' => time()],
            ['etf2l_id' => 2, 'name' => 'Beta', 'computed_at' => null],
        ]);

        $this->assertSame(1, $repository->resetComputed());

        $this->assertNull(DB::table('players')->where('etf2l_id', 1)->value('computed_at'));
        $this->assertNull(DB::table('players')->where('etf2l_id', 2)->value('computed_at'));
    }
}
