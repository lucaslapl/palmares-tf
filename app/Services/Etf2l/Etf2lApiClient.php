<?php

declare(strict_types=1);

namespace App\Services\Etf2l;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client HTTP vers l'API ETF2L v2 (publique, limitée ~60 req/min).
 *
 * Toutes les réponses passent par un cache en base (etf2l_api_cache) avec un
 * TTL par type d'endpoint, et un délai minimum entre deux appels HTTP est
 * respecté pour ne jamais dépasser la limite. En contexte de test, httptest
 * (Http::fake) ainsi que le cache permettent de travailler hors-ligne.
 */
final class Etf2lApiClient
{
    public function __construct(
        private readonly string $baseUrl = 'https://api-v2.etf2l.org',
        private readonly string $userAgent = 'palmares.tf/1.0',
        private readonly float $delayS = 1.1,
        private readonly int $timeoutS = 20,
    ) {}

    /**
     * GET JSON avec cache + throttle + retry.
     *
     * @param  array<string, scalar>  $query
     * @return array<mixed>
     */
    public function getJson(string $path, int $ttl, array $query = []): array
    {
        $url = $this->buildUrl($path, $query);

        $cached = $this->readCache($url, $ttl);
        if ($cached !== null) {
            return $cached;
        }

        $payload = $this->fetchWithRetry($url);
        $this->writeCache($url, $payload);

        return $payload;
    }

    // ---------------------------------------------------------------
    // Endpoints
    // ---------------------------------------------------------------

    /**
     * Page de /competition/list.
     */
    public function competitionListPage(int $page = 1): array
    {
        $ttl = (int) config('palmares.etf2l.cache_ttl.profiles');

        return $this->getJson('/competition/list', $ttl, ['page' => $page]);
    }

    /**
     * Tables de classement final d'une compétition.
     *
     * @return array<string, array<int, array<string, mixed>>> division_name => entries
     */
    public function competitionTables(int $competitionId): array
    {
        $ttl = (int) config('palmares.etf2l.cache_ttl.tables');
        $path = '/competition/'.$competitionId.'/tables';
        $payload = $this->getJson($path, $ttl);
        $tables = $payload['tables'] ?? [];

        // Une réponse sans tables (compétition en cours ou pas encore publiée)
        // ne doit pas être gardée en cache : dès qu'ETF2L publiera les tables,
        // la prochaine passe devra les récupérer sans attendre le TTL.
        if ($tables === []) {
            $this->invalidateCache($path);
        }

        return $tables;
    }

    /**
     * Profil d'une équipe.
     *
     * @return array<string, mixed>
     */
    public function team(int $teamId): array
    {
        $ttl = (int) config('palmares.etf2l.cache_ttl.profiles');
        $payload = $this->getJson('/team/'.$teamId, $ttl);

        return $payload['team'] ?? [];
    }

    /**
     * Page des transferts d'une équipe.
     *
     * @return array<string, mixed>
     */
    public function transfersPage(int $teamId, int $page = 1): array
    {
        $ttl = (int) config('palmares.etf2l.cache_ttl.profiles');

        return $this->getJson('/team/'.$teamId.'/transfers', $ttl, ['page' => $page]);
    }

    /**
     * Profil d'un joueur.
     *
     * @return array<string, mixed>
     */
    public function player(int $playerId): array
    {
        $ttl = (int) config('palmares.etf2l.cache_ttl.profiles');
        $payload = $this->getJson('/player/'.$playerId, $ttl);

        return $payload['player'] ?? [];
    }

    /**
     * Page des résultats d'un joueur.
     *
     * @return array<string, mixed>
     */
    public function playerResultsPage(int $playerId, int $page = 1): array
    {
        $ttl = (int) config('palmares.etf2l.cache_ttl.results');
        $limit = (int) config('palmares.etf2l.results_per_page');

        return $this->getJson('/player/'.$playerId.'/results', $ttl, ['limit' => $limit, 'page' => $page]);
    }

    /**
     * Dernière page d'un payload paginé.
     */
    public function lastPage(array $payload, ?string $wrapper = null): int
    {
        $container = $wrapper !== null ? ($payload[$wrapper] ?? []) : $payload;
        $lastPage = is_array($container) ? (int) ($container['last_page'] ?? 0) : 0;

        return $lastPage > 0 ? $lastPage : 1;
    }

    // ---------------------------------------------------------------
    // Cache
    // ---------------------------------------------------------------

    private function readCache(string $url, int $ttl): ?array
    {
        $payload = DB::table('etf2l_api_cache')
            ->where('url', $url)
            ->where('fetched_at', '>', time() - $ttl)
            ->value('payload');

        if (! is_string($payload) || $payload === '') {
            return null;
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) && $this->isValidPayload($decoded) ? $decoded : null;
    }

    private function writeCache(string $url, array $payload): void
    {
        DB::table('etf2l_api_cache')->upsert(
            [['url' => $url, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'fetched_at' => time()]],
            ['url'],
            ['payload', 'fetched_at'],
        );
    }

    /**
     * Purge une entrée de cache (utilisée pour forcer un re-fetch au prochain
     * appel, e.g. les tables vides d'une compétition encore en cours).
     */
    private function invalidateCache(string $path, array $query = []): void
    {
        DB::table('etf2l_api_cache')
            ->where('url', $this->buildUrl($path, $query))
            ->delete();
    }

    /**
     * Un payload servi depuis le cache n'est exploitable que s'il ressemble à
     * une réponse API (a un bloc status, data, tables, competitions, player,
     * team ou une pagination). Une réponse de throttling ("Too Many Attempts.")
     * est rejetée pour forcer un re-fetch.
     */
    private function isValidPayload(array $payload): bool
    {
        if ($payload === []) {
            return true;
        }

        $markers = ['status', 'data', 'tables', 'competitions', 'player', 'team'];

        foreach ($markers as $marker) {
            if (isset($payload[$marker])) {
                return true;
            }
        }

        return isset($payload['last_page']) || isset($payload['total']);
    }

    // ---------------------------------------------------------------
    // HTTP
    // ---------------------------------------------------------------

    private function buildUrl(string $path, array $query): string
    {
        $url = $this->baseUrl.$path;

        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($query);
        }

        return $url;
    }

    /**
     * Espace chaque appel HTTP d'au moins delayS secondes, tous processus
     * confondus (scheduler, backfill manuel, web à la demande). Un verrou de
     * fichier (flock) sérialise les processus : le débit combiné reste donc
     * sous la limite publique de l'API (~60 req/min) même en cas de tâches
     * planifiées concurrentes.
     */
    private function throttle(): void
    {
        $lockPath = palmares_data_path('api-throttle.lock');
        $dir = dirname($lockPath);
        if (! is_dir($dir) && ! mkdir($dir, 0777, true) && ! is_dir($dir)) {
            // Répertoire illisible : repli sur un délai local seul.
            usleep((int) ($this->delayS * 1e6));

            return;
        }

        $lock = @fopen($lockPath, 'c');
        if ($lock === false) {
            usleep((int) ($this->delayS * 1e6));

            return;
        }

        try {
            flock($lock, LOCK_EX);

            $raw = file_get_contents($lockPath);
            $last = is_string($raw) ? (float) $raw : 0.0;
            $wait = $last + $this->delayS - microtime(true);

            if ($wait > 0) {
                usleep((int) ($wait * 1e6));
            }

            // L'horodatage sert de « dernière sortie d'appel » pour les autres
            // processus ; on le pose sous verrou pour éviter toute course.
            file_put_contents($lockPath, (string) microtime(true));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @return array<mixed>
     */
    private function fetchWithRetry(string $url): array
    {
        $attempts = (int) config('palmares.etf2l.max_attempts', 5);
        $backoffs = (array) config('palmares.etf2l.backoffs', [0, 2, 10, 30, 60]);
        $retryAfter = null;
        $lastError = 'raison inconnue';

        for ($i = 1; $i <= $attempts; $i++) {
            if ($i > 1) {
                // Le header Retry-After de l'API (en secondes) prime sur le
                // backoff fixe ; l'attente est plafonnée à 120 s.
                $wait = $retryAfter ?? ($backoffs[min($i - 1, count($backoffs) - 1)] ?? 0);
                $retryAfter = null;

                if ($wait > 0) {
                    sleep((int) min($wait, 120));
                }
            }

            $this->throttle();

            $response = Http::withHeaders(['User-Agent' => $this->userAgent, 'Accept' => 'application/json'])
                ->timeout($this->timeoutS)
                ->get($url);

            $data = $response->json();
            $httpCode = (int) $response->status();

            if (! is_array($data)) {
                $lastError = 'HTTP '.$httpCode.' avec réponse non-JSON';

                if (in_array($httpCode, [429, 500, 502, 503, 504], true)) {
                    $retryAfter = $this->retryAfterHeader($response);
                }

                continue;
            }

            $code = isset($data['status']['code']) ? (int) $data['status']['code'] : null;

            if ($code === 200 || ($code === null && $httpCode >= 200 && $httpCode < 300)) {
                return $data;
            }

            if ($code === 404) {
                return [];
            }

            if (in_array($code ?? $httpCode, [429, 500, 502, 503, 504], true)) {
                $lastError = 'HTTP '.$httpCode.' (réponse transitoire)';

                if (in_array($code ?? $httpCode, [429], true)) {
                    $retryAfter = $this->retryAfterHeader($response);
                }

                continue;
            }

            throw new RuntimeException("L'API ETF2L a répondu négativement pour {$url} : HTTP {$httpCode}");
        }

        throw new RuntimeException("Appel API ETF2L impossible après {$attempts} tentatives ({$url}) : ".$lastError);
    }

    /**
     * Secondes de retry demandées par l'API (header Retry-After), ou null.
     */
    private function retryAfterHeader(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        return $header !== null ? max(0, (int) $header) : null;
    }
}
