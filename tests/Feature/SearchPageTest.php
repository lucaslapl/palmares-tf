<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PalmaresRepository;
use App\Models\PlayersRepository;
use App\Services\Palmares\LeaderboardBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SearchPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useIsolatedDataDir();

        DB::table('players')->insert([
            ['etf2l_id' => 70031, 'name' => 'kaptain', 'country' => 'European'],
            ['etf2l_id' => 70032, 'name' => 'harbeh', 'country' => 'France'],
        ]);

        (new LeaderboardBuilder(new PalmaresRepository, new PlayersRepository))->rebuildPlayersIndex();
    }

    #[Test]
    public function search_page_returns_matches(): void
    {
        $this->get('/search?q=kapt')
            ->assertOk()
            ->assertSee('kaptain')
            ->assertSee('https://etf2l.org/images/flags/European.gif', false)
            ->assertDontSee('harbeh');
    }

    #[Test]
    public function autocomplete_returns_json(): void
    {
        $response = $this->getJson('/api/search?q=har')
            ->assertOk()
            ->assertJsonCount(1, 'players');

        $this->assertSame('harbeh', $response->json('players.0.name'));
        $this->assertSame(70032, $response->json('players.0.etf2l_id'));
        $this->assertSame('https://etf2l.org/images/flags/France.gif', $response->json('players.0.flag'));
        $this->assertSame(
            route('player.show', ['id' => '70032']),
            $response->json('players.0.url'),
        );
    }

    #[Test]
    public function empty_query_returns_no_suggestion(): void
    {
        $this->getJson('/api/search?q=')
            ->assertOk()
            ->assertJsonCount(0, 'players');
    }
}
