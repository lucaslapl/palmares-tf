<?php

declare(strict_types=1);

namespace App\Models;

use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Agrégats en lecture seule pour le panel admin de monitoring.
 *
 * Fournit : vue d'ensemble du pipeline, santé du scheduler, historique des
 * runs, fraîcheur des JSON publics, qualité des données et métriques du cache
 * API ETF2L. Accès exclusivement via query builder (aucun Eloquent de domaine).
 */
final class AdminDashboardRepository
{
    /** JSON publics surveillés (voir LeaderboardBuilder). */
    private const JSON_FILES = ['leaderboard.json', 'leaderboard-6s.json', 'leaderboard-9v9.json', 'players-index.json'];

    /** Seuil de fraîcheur des JSON (3 h entre deux app:generate-json + marge). */
    private const JSON_MAX_AGE_S = 4 * 3600;

    /** Durée après laquelle une entrée de cache API est jugée obsolète. */
    private const CACHE_MAX_AGE_S = 30 * 86400;

    // ---------------------------------------------------------------
    // Vue d'ensemble
    // ---------------------------------------------------------------

    /**
     * Compteurs clés du pipeline.
     *
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        return [
            'seasons' => (int) DB::table('seasons')->count(),
            'pending_seasons' => (int) DB::table('seasons')->whereNull('ingested_at')->count(),
            'teams' => (int) DB::table('teams')->count(),
            'players' => (int) DB::table('players')->count(),
            'computed' => (int) DB::table('players')->whereNotNull('computed_at')->count(),
            'uncomputed' => (int) DB::table('players')->whereNull('computed_at')->count(),
            'palmares' => (int) DB::table('palmares')->count(),
            'awarded_players' => (int) DB::table('palmares')->distinct()->count('player_id'),
            'medals' => [
                'gold' => (int) DB::table('palmares')->where('medal', 'gold')->count(),
                'silver' => (int) DB::table('palmares')->where('medal', 'silver')->count(),
                'bronze' => (int) DB::table('palmares')->where('medal', 'bronze')->count(),
            ],
            'cache_entries' => (int) DB::table('etf2l_api_cache')->count(),
            'last_cache_fetch' => $this->lastCacheFetch(),
            'db_size' => is_file(database_path('database.sqlite')) ? (int) filesize(database_path('database.sqlite')) : 0,
            'data_dir_size' => $this->dirSize(palmares_data_path()),
        ];
    }

    // ---------------------------------------------------------------
    // Santé du scheduler
    // ---------------------------------------------------------------

    /**
     * Pour chaque tâche planifiée : dernier run et statut (ok / failed /
     * overdue / never). Une tâche est jugée en retard au-delà de deux fois son
     * intervalle de planification.
     *
     * @return array<int, array<string, mixed>>
     */
    public function scheduleHealth(): array
    {
        $definitions = [
            ['command' => 'app:sync-seasons', 'interval_s' => 6 * 3600],
            ['command' => 'app:sync-tables', 'interval_s' => 6 * 3600],
            ['command' => 'app:harvest-players', 'interval_s' => 6 * 3600],
            ['command' => 'app:compute-palmares', 'interval_s' => 30 * 60],
            ['command' => 'app:generate-json', 'interval_s' => 3 * 3600],
        ];

        $rows = [];

        foreach ($definitions as $definition) {
            $last = DB::table('scheduled_command_runs')
                ->where('command', $definition['command'])
                ->orderByDesc('id')
                ->first();

            $ageS = $last !== null ? max(0, time() - (int) $last->started_at) : null;
            $intervalS = (int) $definition['interval_s'];

            $status = match (true) {
                $last === null => 'never',
                $ageS > $intervalS * 2 => 'overdue',
                $last->exit_code !== null && (int) $last->exit_code !== 0 => 'failed',
                default => 'ok',
            };

            $rows[] = [
                'command' => $definition['command'],
                'interval_s' => $intervalS,
                'last_run_s' => $ageS,
                'status' => $status,
                'exit_code' => $last !== null && $last->exit_code !== null ? (int) $last->exit_code : null,
                'running' => $last !== null && $last->finished_at === null && $ageS < $intervalS,
            ];
        }

        return $rows;
    }

    // ---------------------------------------------------------------
    // Fraîcheur des JSON publics
    // ---------------------------------------------------------------

    /**
     * Taille, âge et obsolescence des JSON générés.
     *
     * @return array<int, array<string, mixed>>
     */
    public function jsonFiles(): array
    {
        $files = [];

        foreach (self::JSON_FILES as $name) {
            $path = palmares_data_path($name);

            if (is_file($path)) {
                $files[] = [
                    'name' => $name,
                    'exists' => true,
                    'size' => (int) filesize($path),
                    'age_s' => max(0, time() - (int) filemtime($path)),
                    'stale' => (time() - (int) filemtime($path)) > self::JSON_MAX_AGE_S,
                ];
            } else {
                $files[] = ['name' => $name, 'exists' => false, 'size' => 0, 'age_s' => null, 'stale' => true];
            }
        }

        return $files;
    }

    // ---------------------------------------------------------------
    // Qualité des données
    // ---------------------------------------------------------------

    /**
     * Anomalies détectées en base, à consommer côté vue.
     *
     * @return array<int, array{key: string, label: string, count: int}>
     */
    public function qualityIssues(): array
    {
        $unnamedPlayers = (int) DB::table('players')->whereNull('name')->orWhere('name', '')->count();
        $playersNoCountry = (int) DB::table('players')->whereNull('country')->orWhere('country', '')->count();
        $unnamedTeams = (int) DB::table('teams')->whereNull('name')->orWhere('name', '')->count();

        $deadPalmares = (int) DB::table('palmares')
            ->whereNull('medal')
            ->whereNull('playoff_round')
            ->count();

        $palmaresNoTeam = (int) DB::table('palmares')
            ->whereNull('team_id')
            ->orWhereNull('team_name')
            ->count();

        $computedWithoutPalmares = (int) DB::table('players')
            ->whereNotNull('computed_at')
            ->whereNotIn('id', DB::table('palmares')->select('player_id'))
            ->count();

        $staleCache = (int) DB::table('etf2l_api_cache')
            ->where('fetched_at', '<', time() - self::CACHE_MAX_AGE_S)
            ->count();

        return [
            ['key' => 'uncomputed', 'label' => 'Players never computed', 'count' => (int) DB::table('players')->whereNull('computed_at')->count()],
            ['key' => 'pending_seasons', 'label' => 'Seasons waiting for tables', 'count' => (int) DB::table('seasons')->whereNull('ingested_at')->count()],
            ['key' => 'unnamed_players', 'label' => 'Players without name', 'count' => $unnamedPlayers],
            ['key' => 'players_no_country', 'label' => 'Players without country', 'count' => $playersNoCountry],
            ['key' => 'unnamed_teams', 'label' => 'Teams without name', 'count' => $unnamedTeams],
            ['key' => 'dead_palmares', 'label' => 'Palmares rows without medal or playoff', 'count' => $deadPalmares],
            ['key' => 'palmares_no_team', 'label' => 'Palmares rows without team', 'count' => $palmaresNoTeam],
            ['key' => 'computed_no_palmares', 'label' => 'Computed players without palmares row', 'count' => $computedWithoutPalmares],
            ['key' => 'stale_cache', 'label' => 'API cache entries older than 30 days', 'count' => $staleCache],
        ];
    }

    // ---------------------------------------------------------------
    // Métriques du cache API
    // ---------------------------------------------------------------

    /**
     * Volume du cache ETF2L par endpoint, âge des réponses et état du verrou
     * de throttle.
     *
     * @return array<string, mixed>
     */
    public function apiMetrics(): array
    {
        $rows = DB::table('etf2l_api_cache')->select(['url', 'fetched_at'])->get();

        $byEndpoint = [];
        $maxFetch = 0;
        $minFetch = PHP_INT_MAX;

        foreach ($rows as $row) {
            $fetch = (int) $row->fetched_at;
            $maxFetch = max($maxFetch, $fetch);
            $minFetch = min($minFetch, $fetch);

            $path = (string) parse_url((string) $row->url, PHP_URL_PATH);
            $segments = array_values(array_filter(explode('/', $path)));
            $bucket = $segments !== [] ? (string) $segments[0] : 'other';

            $byEndpoint[$bucket]['count'] = ($byEndpoint[$bucket]['count'] ?? 0) + 1;
            $byEndpoint[$bucket]['last_fetch'] = max($byEndpoint[$bucket]['last_fetch'] ?? 0, $fetch);
        }

        uksort(
            $byEndpoint,
            static fn (string $a, string $b): int => ($byEndpoint[$b]['count'] ?? 0) <=> ($byEndpoint[$a]['count'] ?? 0),
        );

        $throttlePath = palmares_data_path('api-throttle.lock');
        $throttleValue = is_file($throttlePath) ? (float) (file_get_contents($throttlePath) ?: 0) : 0.0;

        return [
            'base_url' => (string) config('palmares.etf2l.base_url'),
            'by_endpoint' => $byEndpoint,
            'last_fetch' => $maxFetch > 0 ? $maxFetch : null,
            'oldest_fetch' => $minFetch !== PHP_INT_MAX ? $minFetch : null,
            'throttle' => [
                'exists' => is_file($throttlePath),
                'age_s' => is_file($throttlePath) ? max(0, time() - (int) filemtime($throttlePath)) : null,
                'value' => $throttleValue,
            ],
        ];
    }

    // ---------------------------------------------------------------
    // Journaux
    // ---------------------------------------------------------------

    /**
     * Dernières lignes du journal du scheduler (nouveau d'abord).
     *
     * @return array<int, string>
     */
    public function tailScheduleLog(int $lines = 100): array
    {
        return $this->tailFile(storage_path('logs/schedule.log'), $lines);
    }

    /**
     * Dernières lignes de laravel.log relatives aux commandes app:*, avec leur
     * sévérité, les plus récentes d'abord.
     *
     * @return array<int, array{severity: string, message: string}>
     */
    public function tailAppLog(int $lines = 120): array
    {
        $all = $this->readFile(storage_path('logs/laravel.log'));
        if ($all === null) {
            return [];
        }

        $filtered = array_values(array_filter($all, static fn (string $line): bool => str_contains($line, 'app:')));

        return array_map(
            static function (string $line): array {
                $matches = [];
                preg_match('/\]\s+[\w.]+\.(DEBUG|INFO|NOTICE|WARNING|ERROR|CRITICAL|ALERT|EMERGENCY):\s*(.*)$/', $line, $matches);
                $severity = $matches[1] ?? 'INFO';
                $message = trim($matches[2] ?? $line);

                return ['severity' => $severity, 'message' => $message];
            },
            array_slice(array_reverse($filtered), 0, $lines),
        );
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function lastCacheFetch(): ?int
    {
        $max = DB::table('etf2l_api_cache')->max('fetched_at');

        return $max !== null ? (int) $max : null;
    }

    private function tailFile(string $path, int $lines): array
    {
        $all = $this->readFile($path);

        return $all !== null ? array_slice(array_reverse($all), 0, $lines) : [];
    }

    /**
     * @return array<int, string>|null
     */
    private function readFile(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if (! is_string($content) || $content === '') {
            return [];
        }

        return preg_split('/\r?\n/', rtrim($content, "\n")) ?: [];
    }

    private function dirSize(string $dir): int
    {
        if (! is_dir($dir)) {
            return 0;
        }

        $size = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += (int) $file->getSize();
            }
        }

        return $size;
    }
}
