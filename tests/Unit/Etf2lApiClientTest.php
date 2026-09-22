<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Etf2l\Etf2lApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class Etf2lApiClientTest extends TestCase
{
    use RefreshDatabase;

    private Etf2lApiClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        // Hôte de test : jamais de requête vers la vraie API.
        $this->client = new Etf2lApiClient('https://api.example.test', 'palmares-test', 0.0, 5);
    }

    #[Test]
    public function cache_miss_fetches_and_stores_payload(): void
    {
        Http::fake([
            'api.example.test/*' => Http::response(['status' => ['code' => 200], 'data' => ['ok' => true]]),
        ]);

        $payload = $this->client->getJson('/player/70031', 3600);

        $this->assertEquals(['status' => ['code' => 200], 'data' => ['ok' => true]], $payload);

        $this->assertDatabaseHas('etf2l_api_cache', [
            'url' => 'https://api.example.test/player/70031',
        ]);
    }

    #[Test]
    public function cache_hit_skips_http_call(): void
    {
        $url = 'https://api.example.test/player/70031/results?limit=50&page=1';

        DB::table('etf2l_api_cache')->insert([
            'url' => $url,
            'payload' => json_encode(['current_page' => 1, 'data' => [], 'last_page' => 1]),
            'fetched_at' => time(),
        ]);

        Http::fake(fn () => throw new \LogicException('Aucun appel HTTP ne doit être émis.'));

        $payload = $this->client->playerResultsPage(70031, 1);

        $this->assertEquals(['current_page' => 1, 'data' => [], 'last_page' => 1], $payload);
    }

    #[Test]
    public function invalid_throttled_payload_requires_refetch(): void
    {
        $url = 'https://api.example.test/player/70031';

        // Réponse throttlée ("Too Many Attempts.") sans bloc status : ne doit
        // jamais être servie depuis le cache.
        DB::table('etf2l_api_cache')->insert([
            'url' => $url,
            'payload' => json_encode(['message' => 'Too Many Attempts.']),
            'fetched_at' => time(),
        ]);

        Http::fake([
            'api.example.test/*' => Http::response(['status' => ['code' => 200], 'player' => ['id' => 70031]]),
        ]);

        $payload = $this->client->getJson('/player/70031', 3600);

        $this->assertEquals(['status' => ['code' => 200], 'player' => ['id' => 70031]], $payload);
    }

    #[Test]
    public function not_found_returns_empty_payload_and_is_cached(): void
    {
        Http::fake([
            'api.example.test/*' => Http::response(['status' => ['code' => 404]], 200),
        ]);

        $this->assertSame([], $this->client->getJson('/player/0', 3600));
        $this->assertDatabaseHas('etf2l_api_cache', ['url' => 'https://api.example.test/player/0']);
    }

    #[Test]
    public function last_page_resolves_wrapped_and_unwrapped_pagination(): void
    {
        $this->assertSame(4, $this->client->lastPage(['last_page' => 4, 'data' => []]));
        $this->assertSame(3, $this->client->lastPage(['competitions' => ['last_page' => 3]], 'competitions'));
        $this->assertSame(1, $this->client->lastPage(['data' => []]));
    }

    #[Test]
    public function data_dir_isolated_in_tests(): void
    {
        $this->useIsolatedDataDir();
        $this->assertStringContainsString('palmares-test', palmares_data_path());
    }
}
