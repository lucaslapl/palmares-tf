<?php

declare(strict_types=1);

namespace App\Services\Palmares;

use App\Models\PalmaresRepository;
use App\Models\PlayersRepository;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Génère les JSON publics de palmares.tf (leaderboards + index de recherche)
 * sous storage/app/palmares, dans l'esprit des caches JSON du site Highlander
 * France : écriture atomique sous verrou de fichier, et régénération à la
 * demande si le fichier est absent ou obsolète ("self-healing").
 */
final class LeaderboardBuilder
{
    private const LOCK_FILE = 'leaderboards.lock';

    public function __construct(
        private readonly PalmaresRepository $palmares,
        private readonly PlayersRepository $players,
    ) {}

    /**
     * Régénère les trois leaderboards et l'index de recherche.
     *
     * @return array{leaderboard_all: int, leaderboard_6v6: int, leaderboard_9v9: int, players_index: int}
     */
    public function rebuildAll(): array
    {
        return $this->withLock(function (): array {
            $all = $this->rebuildLeaderboard(null);
            $s6 = $this->rebuildLeaderboard('6s');
            $s9 = $this->rebuildLeaderboard('9v9');
            $index = $this->rebuildPlayersIndex();

            return [
                'leaderboard_all' => $all,
                'leaderboard_6v6' => $s6,
                'leaderboard_9v9' => $s9,
                'players_index' => $index,
            ];
        });
    }

    /**
     * @return int nombre de joueurs écrits
     */
    public function rebuildLeaderboard(?string $format = null): int
    {
        $rows = $this->palmares->aggregateByPlayer($format);

        $now = time();

        $players = array_map(function (object $row) use ($now): array {
            $banUntil = $row->ban_until !== null ? (int) $row->ban_until : null;

            return [
                'etf2l_id' => $row->etf2l_id !== null ? (int) $row->etf2l_id : 0,
                'name' => (string) $row->name,
                'country' => $row->country !== null ? (string) $row->country : '',
                'steam_id64' => $row->steam_id64 !== null ? (string) $row->steam_id64 : '',
                'avatar' => $row->avatar !== null ? (string) $row->avatar : '',
                'ban_until' => $banUntil,
                'banned' => $banUntil !== null && $banUntil > $now,
                'points' => (int) $row->points,
                'golds' => (int) $row->golds,
                'silvers' => (int) $row->silvers,
                'bronzes' => (int) $row->bronzes,
                'awards' => (int) $row->awards,
            ];
        }, $rows);

        usort($players, function (array $a, array $b): int {
            foreach (['points', 'golds', 'silvers', 'bronzes'] as $field) {
                if ($a[$field] !== $b[$field]) {
                    return $b[$field] <=> $a[$field];
                }
            }

            return strcasecmp($a['name'], $b['name']);
        });

        $suffix = $format !== null ? '-'.$format : '';
        $this->writeJson('leaderboard'.$suffix.'.json', [
            'generated_at' => time(),
            'format' => $format,
            'players' => $players,
        ]);

        return count($players);
    }

    /**
     * @return int nombre de joueurs indexés
     */
    public function rebuildPlayersIndex(): int
    {
        $players = $this->players->allForIndex();
        $this->writeJson('players-index.json', ['generated_at' => time(), 'players' => $players]);

        return count($players);
    }

    /**
     * Lecture du leaderboard avec régénération si fichier absent/obsolète.
     *
     * @return array<string, mixed> payload complet du fichier, ou [] si aucun joueur
     */
    public function readLeaderboard(?string $format = null, int $maxAgeS = 3600): array
    {
        $file = 'leaderboard'.($format !== null ? '-'.$format : '').'.json';

        try {
            return $this->readOrRebuild($file, fn (): int => $this->rebuildLeaderboard($format), $maxAgeS);
        } catch (Throwable $e) {
            // Dernier recours (verrou indisponible…) : sans ce journal, une
            // page vide serait indissociable d'un leaderboard réellement vide.
            Log::error('Lecture du leaderboard impossible', ['file' => $file, 'error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function readPlayersIndex(int $maxAgeS = 3600): array
    {
        return $this->readOrRebuild('players-index.json', fn (): int => $this->rebuildPlayersIndex(), $maxAgeS);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Lecture avec régénération si fichier absent/obsolète. Si le rebuild à la
     * lecture échoue (erreur DB, encodage…), le JSON périmé existant est servi
     * plutôt qu'une page vide, et l'échec est journalisé pour diagnostic. Le
     * rebuild programmé (app:generate-json), lui, n'avale pas : la commande
     * sortira en échec.
     *
     * @param  callable(): int  $rebuild
     * @return array<string, mixed>
     */
    private function readOrRebuild(string $file, callable $rebuild, int $maxAgeS): array
    {
        $path = palmares_data_path($file);
        $isStale = static fn (): bool => ! is_file($path) || (time() - (int) filemtime($path)) > $maxAgeS;

        if ($isStale()) {
            return $this->withLock(function () use ($isStale, $rebuild, $file): array {
                if ($isStale()) {
                    try {
                        $rebuild();
                    } catch (Throwable $e) {
                        Log::warning('Régénération du JSON à la lecture échouée : JSON périmé servi', [
                            'file' => $file,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                return $this->readJson($file);
            });
        }

        return $this->readJson($file);
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $file): array
    {
        $payload = @file_get_contents(palmares_data_path($file));
        if (! is_string($payload) || $payload === '') {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeJson(string $file, array $data): void
    {
        $dir = palmares_data_path();
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("Impossible de créer le répertoire {$dir}");
        }

        $tmp = $dir.'/.'.$file.'.'.bin2hex(random_bytes(4)).'.tmp';
        $content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Un échec d'encodage (UTF-8 invalide venu de l'API, par ex.) ne doit
        // jamais remplacer le JSON existant par un fichier vide : on jette
        // avant d'écrire, l'ancien fichier reste servi.
        if (! is_string($content)) {
            throw new RuntimeException("Encodage JSON impossible pour {$file} (données invalides)");
        }

        if (file_put_contents($tmp, $content) === false || ! rename($tmp, $dir.'/'.$file)) {
            @unlink($tmp);

            throw new RuntimeException("Impossible d'écrire le fichier {$file}");
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withLock(callable $callback): mixed
    {
        $dir = palmares_data_path();
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("Impossible de créer le répertoire {$dir}");
        }

        $lock = fopen($dir.'/'.self::LOCK_FILE, 'c');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }

            throw new RuntimeException('Verrou des leaderboards indisponible.');
        }

        try {
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
