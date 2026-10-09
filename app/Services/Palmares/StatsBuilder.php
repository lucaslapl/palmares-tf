<?php

declare(strict_types=1);

namespace App\Services\Palmares;

use App\Models\SeasonsRepository;
use App\Models\StatsRepository;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Génère le JSON public des statistiques communautaires (stats.json) sous
 * storage/app/palmares, sur le modèle des leaderboards : écriture atomique
 * sous verrou de fichier, régénération à la lecture si absent/obsolète
 * ("self-healing").
 *
 * Les séries temporelles regroupent les participations (joueurs ayant joué
 * au moins un match, médaille ou pas) par « saison logique » (même
 * normalisation que ComputePalmaresService::seasonKey) : les compétitions
 * divisionnées ("Season 52 : Division 2") sont rattachées à leur saison,
 * les sous-compétitions satellites (playoffs isolés, qualifications,
 * signups) sont exclues.
 */
final class StatsBuilder
{
    private const FILE = 'stats.json';

    private const LOCK_FILE = 'stats.lock';

    public function __construct(
        private readonly StatsRepository $stats,
    ) {}

    /**
     * Reconstruit le JSON des stats.
     *
     * @return array<string, mixed> payload écrit
     */
    public function rebuild(): array
    {
        return $this->withLock(fn (): array => $this->buildAndWrite());
    }

    /**
     * Calcule le payload et l'écrit, sans verrou (appelé déjà sous verrou).
     *
     * @return array<string, mixed>
     */
    private function buildAndWrite(): array
    {
        $payload = $this->compute();
        $this->writeJson(self::FILE, $payload);

        return $payload;
    }

    /**
     * Lecture du JSON avec régénération si absent/obsolète (self-healing).
     *
     * @return array<string, mixed> payload complet du fichier, ou [] si vide
     */
    public function read(int $maxAgeS = 6 * 3600): array
    {
        try {
            return $this->readOrRebuild(self::FILE, fn (): array => $this->buildAndWrite(), $maxAgeS);
        } catch (Throwable $e) {
            // Sans ce journal, une page vide serait indissociable d'une
            // absence réelle de données.
            Log::error('Lecture des stats impossible', ['file' => self::FILE, 'error' => $e->getMessage()]);

            return [];
        }
    }

    // ---------------------------------------------------------------
    // Construction du payload
    // ---------------------------------------------------------------

    /**
     * Agrège le palmarès en séries par saison logique et par année.
     *
     * @return array<string, mixed>
     */
    private function compute(): array
    {
        $seasonRows = $this->stats->leagueSeasons();
        $teamCounts = $this->stats->teamCountsBySeason();

        // Saisons logiques de ligue : clé => première saison « principale ».
        /** @var array<string, array{name: string, format: string}> $leagueKeys */
        $leagueKeys = [];
        /** @var array<int, string> $seasonIdToKey */
        $seasonIdToKey = [];
        foreach ($seasonRows as $season) {
            $name = (string) $season->name;
            $format = (string) $season->format;
            $key = ComputePalmaresService::seasonKey($format, $name);

            $seasonIdToKey[(int) $season->id] = $key;

            if (! $this->isSatellite($name) && ! isset($leagueKeys[$key])) {
                $leagueKeys[$key] = ['name' => $this->logicalName($name), 'format' => $format];
            }
        }

        // Équipes et divisions distinctes par saison logique.
        /** @var array<string, array{teams: int, divisions: int}> $keyTeams */
        $keyTeams = [];
        foreach ($teamCounts as $row) {
            $key = $seasonIdToKey[(int) $row->season_id] ?? null;
            if ($key === null) {
                continue;
            }

            if (! isset($keyTeams[$key])) {
                $keyTeams[$key] = ['teams' => 0, 'divisions' => 0];
            }

            $keyTeams[$key]['teams'] += (int) $row->teams;
            $keyTeams[$key]['divisions'] += (int) $row->divisions;
        }

        // Joueurs par saison logique (ligue) et compétitions hors ligue.
        /** @var array<string, array{players: array<int, true>, end_time: int}> $leaguePlayers */
        $leaguePlayers = [];
        /** @var array<string, array{name: string, format: string, players: array<int, true>, end_time: int}> $otherCompetitions */
        $otherCompetitions = [];

        foreach ($this->stats->participations() as $row) {
            $playerId = (int) $row->player_id;
            $time = (int) ($row->season_time ?? 0);
            $format = (string) ($row->season_format ?? $row->format ?? '');
            $name = (string) ($row->season_name ?? $row->competition_name ?? '');

            if ($row->season_id !== null) {
                $key = $seasonIdToKey[(int) $row->season_id] ?? null;
                if ($key === null || ! isset($leagueKeys[$key])) {
                    continue;
                }

                $leaguePlayers[$key]['players'][$playerId] = true;
                $leaguePlayers[$key]['end_time'] = max($leaguePlayers[$key]['end_time'] ?? 0, $time);

                continue;
            }

            // Compétition hors table de ligue (Nations Cup, coupes) : pas de
            // saison rattachée, on regroupe par nom de compétition logique.
            $key = ComputePalmaresService::seasonKey($format, $name);
            if ($this->isSatellite($name)) {
                continue;
            }

            $otherCompetitions[$key]['name'] = $this->logicalName($name);
            $otherCompetitions[$key]['format'] = $format;
            $otherCompetitions[$key]['players'][$playerId] = true;
            $otherCompetitions[$key]['end_time'] = max($otherCompetitions[$key]['end_time'] ?? 0, $time);
        }

        // Séries par format, ordonnées chronologiquement, avec nouvelles
        // recrues vs joueurs qui reviennent.
        $seasons = [];
        $years = [];

        foreach (['6s', '9v9'] as $format) {
            $entries = [];
            foreach ($leagueKeys as $key => $meta) {
                if ($meta['format'] !== $format) {
                    continue;
                }

                $players = $leaguePlayers[$key]['players'] ?? [];
                $endTime = (int) ($leaguePlayers[$key]['end_time'] ?? 0);

                // Saison non datable (aucun match connu) : hors série.
                if ($endTime <= 0 || $players === []) {
                    continue;
                }

                $entries[] = [
                    'key' => $key,
                    'name' => $meta['name'],
                    'format' => $format,
                    'end_time' => $endTime,
                    'year' => (int) gmdate('Y', $endTime),
                    'players' => count($players),
                    'teams' => $keyTeams[$key]['teams'] ?? 0,
                    'divisions' => $keyTeams[$key]['divisions'] ?? 0,
                    '_players' => array_map(intval(...), array_keys($players)),
                ];
            }

            usort($entries, static fn (array $a, array $b): int => $a['end_time'] <=> $b['end_time']);

            // Nouveaux joueurs : première apparition dans le format.
            $seen = [];
            foreach ($entries as $index => $entry) {
                $new = 0;
                foreach ($entry['_players'] as $playerId) {
                    if (! isset($seen[$playerId])) {
                        $seen[$playerId] = true;
                        $new++;
                    }
                }

                unset($entries[$index]['_players']);
                $entries[$index]['new_players'] = $new;
                $entries[$index]['returning_players'] = $entry['players'] - $new;

                $years[(int) $entry['year']][$format] = ($years[(int) $entry['year']][$format] ?? 0) + 1;
            }

            $seasons[$format] = $entries;
        }

        // Compétitions hors ligue par année (Nations Cups, coupes).
        $otherByYear = [];
        foreach ($otherCompetitions as $meta) {
            if ($meta['end_time'] <= 0) {
                continue;
            }

            $year = (int) gmdate('Y', $meta['end_time']);
            $otherByYear[$year] = ($otherByYear[$year] ?? 0) + 1;
        }

        ksort($years);

        $yearEntries = [];
        foreach ($years as $year => $byFormat) {
            $yearEntries[] = [
                'year' => $year,
                'seasons_6s' => $byFormat['6s'] ?? 0,
                'seasons_9v9' => $byFormat['9v9'] ?? 0,
                'other' => $otherByYear[$year] ?? 0,
            ];
        }

        // Joueurs distincts ayant participé (ligue et compétitions hors ligue),
        // pour le résumé de page : l'activité réelle, pas seulement les médaillés.
        $playersSeen = [];
        foreach ($leaguePlayers as $meta) {
            foreach (array_keys($meta['players']) as $playerId) {
                $playersSeen[(int) $playerId] = true;
            }
        }
        foreach ($otherCompetitions as $meta) {
            foreach (array_keys($meta['players']) as $playerId) {
                $playersSeen[(int) $playerId] = true;
            }
        }

        return [
            'generated_at' => time(),
            'formats' => (array) config('palmares.formats'),
            'seasons' => $seasons,
            'years' => $yearEntries,
            'totals' => $this->totals($seasons, $otherCompetitions, $playersSeen),
        ];
    }

    /**
     * Résumés affichés en tête de page.
     *
     * @param  array<string, array<int, array<string, mixed>>>&array<string, mixed>  $seasons
     * @param  array<string, array{name: string, format: string, players: array<int, true>, end_time: int}>  $otherCompetitions
     * @param  array<int, true>  $playersSeen
     * @return array<string, int>
     */
    private function totals(array $seasons, array $otherCompetitions, array $playersSeen): array
    {
        $teams = 0;
        foreach (['6s', '9v9'] as $format) {
            foreach ($seasons[$format] ?? [] as $entry) {
                $teams += (int) $entry['teams'];
            }
        }

        return [
            'league_seasons' => count($seasons['6s'] ?? []) + count($seasons['9v9'] ?? []),
            'other_competitions' => count($otherCompetitions),
            'players' => count($playersSeen),
            'medal_players' => $this->stats->medalPlayersCount(),
            'team_slots' => $teams,
        ];
    }

    /**
     * Sous-compétition jamais comptée comme une saison : playoffs isolés
     * (« - Premiership Playoffs »), 3e place, qualifications, signups. Les
     * satellites divisionnés via « : » sont déjà fondus dans la saison
     * logique par la normalisation du nom.
     */
    private function isSatellite(string $name): bool
    {
        return SeasonsRepository::isPlayoffCompetition($name)
            || stripos($name, 'signup') !== false;
    }

    /**
     * Libellé lisible d'une saison logique : le nom normalisé, sans le
     * suffixe de division (« : Division 2 ») ni la saison entre parenthèses.
     */
    private function logicalName(string $name): string
    {
        $normalized = preg_replace('/\s*\([^)]*\)/u', '', $name) ?? $name;
        $normalized = preg_replace('/\s*:.*$/u', '', $normalized) ?? $normalized;

        return trim($normalized);
    }

    // ---------------------------------------------------------------
    // Lecture / écriture JSON (même modèle que LeaderboardBuilder)
    // ---------------------------------------------------------------

    /**
     * Lecture avec régénération si fichier absent/obsolète. Si le rebuild à
     * la lecture échoue, le JSON périmé existant est servi plutôt qu'une
     * page vide, et l'échec est journalisé.
     *
     * @param  callable(): array<string, mixed>  $rebuild
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
                        Log::warning('Régénération des stats à la lecture échouée : JSON périmé servi', [
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

            throw new RuntimeException('Verrou des stats indisponible.');
        }

        try {
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
