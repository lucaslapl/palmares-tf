<?php

declare(strict_types=1);

namespace App\Services\Palmares;

use App\Models\PalmaresRepository;
use App\Models\PlayersRepository;
use App\Services\Etf2l\Etf2lApiClient;
use RuntimeException;
use Throwable;

/**
 * Calcul du palmarès ETF2L joueur par joueur.
 *
 * Principe : pour chaque joueur, on récupère la liste de ses matches
 * (/player/{id}/results), on croise avec les tables de classement final
 * (champ "ach") pour en déduire les podiums, et on détecte les rounds de
 * playoffs significatifs. Les entrées sont ensuite dédoublonnées par saison
 * logique (saison régulière + playoffs séparés) puis stockées en base.
 *
 * Seules les compétitions avec un résultat positif sont retenues : podium
 * (or/argent/bronze) ou participation à un round de playoffs. Les Nations Cup
 * ne comptent qu'à partir du podium (déduit des finales).
 */
final class ComputePalmaresService
{
    /**
     * Rounds de playoffs significatifs, par ordre de spécificité décroissant.
     * Première regex qui matche dans la chaîne "round" est retenue.
     */
    private const PLAYOFF_PATTERNS = [
        '/grand\s*final/i' => 'Grand Final',
        '/upper\s*bracket\s*final/i' => 'Upper Bracket Final',
        '/winner.?s?.?bracket.*final/i' => 'Upper Bracket Final',
        '/lower\s*bracket\s*final/i' => 'Lower Bracket Final',
        '/loser.?s?.?bracket.*final/i' => 'Lower Bracket Final',
        '/bracket\s*final/i' => 'Bracket Final',
        '/quarter.?final/i' => 'Quarter-final',
        '/semi.?final/i' => 'Semi-final',
        '/final/i' => 'Final',
        '/round\s*of\s*16/i' => 'Round of 16',
        '/lower\s*bracket/i' => 'Playoffs',
        '/upper\s*br[ae]cker.*round/i' => 'Playoffs',
        '/upper\s*bracket/i' => 'Playoffs',
        '/bracket/i' => 'Playoffs',
        '/playoff/i' => 'Playoffs',
    ];

    /**
     * Ordre de prestige des rounds (0 = le plus prestigieux).
     */
    private const PLAYOFF_PRESTIGE = [
        'Grand Final',
        'Upper Bracket Final',
        'Lower Bracket Final',
        'Bracket Final',
        'Final',
        'Semi-final',
        'Quarter-final',
        'Round of 16',
        'Playoffs',
    ];

    public function __construct(
        private readonly Etf2lApiClient $client,
        private readonly PlayersRepository $players,
        private readonly PalmaresRepository $palmares,
    ) {}

    // ---------------------------------------------------------------
    // Point d'entrée public
    // ---------------------------------------------------------------

    /**
     * Calcule et stocke le palmarès d'un joueur (identifiant ETF2L).
     *
     * @return array<int, array<string, mixed>> entrées enrichies (format, medal, season_time)
     */
    public function computeForPlayer(int $etf2lPlayerId): array
    {
        $player = $this->players->findByEtf2lId($etf2lPlayerId);
        if ($player === null) {
            // Profil demandé hors pipeline (vue web) : on crée le joueur à la volée.
            $api = $this->client->player($etf2lPlayerId);
            if ($api === []) {
                return [];
            }
            $this->players->upsertFromApi($api);
            $player = $this->players->findByEtf2lId($etf2lPlayerId);
            if ($player === null) {
                return [];
            }
        }

        $entries = $this->computeEntries($etf2lPlayerId);
        $this->palmares->replaceForPlayer((int) $player->id, $entries);
        $this->players->markComputed((int) $player->id);

        return $entries;
    }

    /**
     * Y a-t-il encore des joueurs à traiter (backfill) ?
     */
    public function hasPendingPlayers(): bool
    {
        return $this->players->pending(1) !== [];
    }

    /**
     * Traitement en lot des joueurs en attente (app:compute-palmares), sous
     * verrou de fichier pour empêcher toute exécution concurrente.
     *
     * @return array{computed: int, failed: int, skipped: int, errors: list<string>}
     */
    public function runBackfill(?callable $progress = null, int $limit = 0, int $maxRuntimeS = 1500): array
    {
        $lock = fopen(palmares_data_path('compute-palmares.lock'), 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }

            throw new RuntimeException('Calcul du palmarès déjà en cours (une autre exécution est active).');
        }

        try {
            return $this->doBackfill($progress, $limit, $maxRuntimeS);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @return array{computed: int, failed: int, skipped: int, errors: list<string>}
     */
    private function doBackfill(?callable $progress, int $limit, int $maxRuntimeS): array
    {
        $stats = ['computed' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => []];
        $start = time();

        while (true) {
            $batch = $this->players->pending(50);
            if ($batch === []) {
                break;
            }

            foreach ($batch as $player) {
                if ($limit > 0 && $stats['computed'] >= $limit) {
                    $stats['skipped'] = count($batch) - ($stats['computed'] === $limit ? 0 : $stats['computed']);

                    break 2;
                }

                if ($maxRuntimeS > 0 && time() - $start > $maxRuntimeS) {
                    $stats['skipped'] += count($batch) - $stats['computed'];

                    break 2;
                }

                try {
                    $this->computeForPlayer((int) $player->etf2l_id);
                    $stats['computed']++;

                    if ($progress !== null) {
                        $progress($player);
                    }
                } catch (Throwable $e) {
                    $stats['failed']++;
                    $stats['errors'][] = $player->etf2l_id.' : '.$e->getMessage();
                }
            }
        }

        return $stats;
    }

    // ---------------------------------------------------------------
    // Récupération des données
    // ---------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchResults(int $playerId): array
    {
        $results = [];
        $page = 1;

        do {
            $payload = $this->client->playerResultsPage($playerId, $page);
            $pageResults = $payload['data'] ?? [];

            if (is_array($pageResults) && $pageResults !== []) {
                $results[] = $pageResults;
            }

            if ($page >= $this->client->lastPage($payload)) {
                break;
            }
            $page++;
        } while (true);

        return $results === [] ? [] : array_merge(...$results);
    }

    // ---------------------------------------------------------------
    // Extraction
    // ---------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>|null
     */
    private function extractResultInfo(array $result): ?array
    {
        $seasonCategories = (array) config('palmares.categories.seasons');
        $nationsCategories = (array) config('palmares.categories.nations_cup');

        $category = (string) ($result['competition']['category'] ?? '');
        $isSeason = in_array($category, $seasonCategories, true);
        $isNations = in_array($category, $nationsCategories, true);
        if (! $isSeason && ! $isNations) {
            return null;
        }

        $type = (string) ($result['competition']['type'] ?? '');
        $compName = (string) ($result['competition']['name'] ?? '');
        $gameMode = $this->resolveGameMode($type, $category, $compName);
        if ($gameMode === null) {
            return null;
        }

        // Les compétitions de qualification ne sont pas représentatives : ignorées.
        if (stripos($compName, 'qualif') !== false) {
            return null;
        }

        // Identifier l'équipe du joueur.
        $teamId = 0;
        $teamName = '';
        foreach (['clan1', 'clan2'] as $side) {
            $clan = $result[$side] ?? null;
            if (is_array($clan) && ($clan['was_in_team'] ?? false) === true) {
                $teamId = (int) ($clan['id'] ?? 0);
                $teamName = (string) ($clan['name'] ?? '');
                break;
            }
        }

        if ($teamId <= 0) {
            return null;
        }

        $division = $result['division'] ?? null;

        return [
            'competition_id' => (int) ($result['competition']['id'] ?? 0),
            'competition_name' => (string) ($result['competition']['name'] ?? ''),
            'competition_category' => $category,
            'team_id' => $teamId,
            'team_name' => $teamName,
            'division_name' => is_array($division) ? (string) ($division['name'] ?? '') : '',
            'round' => (string) ($result['round'] ?? ''),
            'game_mode' => $gameMode,
        ];
    }

    private function resolveGameMode(string $type, string $category, string $compName): ?string
    {
        $modeMap = (array) config('palmares.mode_map');
        $nationsModeMap = (array) config('palmares.nations_mode_map');
        $nationsCategories = (array) config('palmares.categories.nations_cup');

        if (in_array($category, $nationsCategories, true)) {
            if (isset($nationsModeMap[$type])) {
                return (string) $nationsModeMap[$type];
            }

            if (stripos($compName, 'Highlander') !== false) {
                return '9v9';
            }
            if (stripos($compName, '6v6') !== false) {
                return '6s';
            }
            if (isset($modeMap[$type])) {
                return (string) $modeMap[$type];
            }

            return null;
        }

        // Saisons de ligue : la catégorie est l'indicateur fiable (certaines
        // compétitions Highlander ont un type "6v6" erroné dans l'API).
        if (in_array($category, (array) config('palmares.categories.seasons'), true)) {
            if ($category === 'Highlander Season' || stripos($compName, 'Highlander') !== false) {
                return '9v9';
            }
            if ($category === '6v6 Season' || stripos($compName, '6v6') !== false) {
                return '6s';
            }
        }

        return isset($modeMap[$type]) ? (string) $modeMap[$type] : null;
    }

    // ---------------------------------------------------------------
    // Classement et playoffs
    // ---------------------------------------------------------------

    /**
     * Placement d'une équipe dans une compétition via le champ "ach" des tables.
     */
    private function resolvePlacement(int $competitionId, int $teamId): ?int
    {
        $tables = $this->client->competitionTables($competitionId);

        foreach ($tables as $divisionEntries) {
            foreach ($divisionEntries as $entry) {
                if ((int) ($entry['id'] ?? 0) !== $teamId) {
                    continue;
                }

                $ach = $entry['ach'] ?? null;

                return is_int($ach) && $ach >= 1 && $ach <= 3 ? $ach : null;
            }
        }

        return null;
    }

    /**
     * Meilleur round de playoffs atteint par le joueur dans une compétition.
     *
     * @param  array<int, array<string, mixed>>  $compResults
     * @return array{0: string|null, 1: bool} [round, won]
     */
    private function bestPlayoffRound(array $compResults): array
    {
        $bestLabel = null;
        $bestWon = false;
        $bestPrestige = PHP_INT_MAX;
        $prestigeMap = array_flip(self::PLAYOFF_PRESTIGE);

        foreach ($compResults as $result) {
            $round = (string) ($result['round'] ?? '');

            foreach (self::PLAYOFF_PATTERNS as $pattern => $label) {
                if (preg_match($pattern, $round) !== 1) {
                    continue;
                }

                $prestige = $prestigeMap[$label] ?? count(self::PLAYOFF_PRESTIGE);

                if ($prestige < $bestPrestige) {
                    $bestPrestige = $prestige;
                    $bestLabel = $label;
                    $bestWon = $this->matchWonByPlayer($result);
                }

                break;
            }
        }

        return [$bestLabel, $bestWon];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function matchWonByPlayer(array $result): bool
    {
        $playerSide = null;

        foreach (['clan1', 'clan2'] as $side) {
            $clan = $result[$side] ?? null;
            if (is_array($clan) && ($clan['was_in_team'] ?? false) === true) {
                $playerSide = $side;
                break;
            }
        }

        if ($playerSide === null) {
            return false;
        }

        $r1 = (int) ($result['r1'] ?? 0);
        $r2 = (int) ($result['r2'] ?? 0);

        return $playerSide === 'clan1' ? $r1 > $r2 : $r2 > $r1;
    }

    /**
     * Équipe représentative d'une compétition : la plus rencontrée.
     *
     * @param  array<int, array<string, mixed>>  $infos
     * @return array<string, mixed>
     */
    private function majorityInfo(array $infos): array
    {
        $counts = [];
        foreach ($infos as $info) {
            $teamId = (int) $info['team_id'];
            $counts[$teamId] = ($counts[$teamId] ?? 0) + 1;
        }
        arsort($counts);
        $bestTeamId = (int) array_key_first($counts);

        if ($bestTeamId > 0) {
            foreach ($infos as $info) {
                if ((int) $info['team_id'] === $bestTeamId) {
                    return $info;
                }
            }
        }

        return $infos[0];
    }

    // ---------------------------------------------------------------
    // Calcul
    // ---------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    private function computeEntries(int $playerId): array
    {
        $results = $this->fetchResults($playerId);
        if ($results === []) {
            return [];
        }

        // 1. Regrouper par compétition.
        $byCompetition = [];

        foreach ($results as $result) {
            $info = $this->extractResultInfo($result);
            if ($info === null || $info['competition_id'] === 0) {
                continue;
            }

            $compId = $info['competition_id'];

            if (! isset($byCompetition[$compId])) {
                $byCompetition[$compId] = ['infos' => [], 'matches' => []];
            }

            $byCompetition[$compId]['infos'][] = $info;
            $byCompetition[$compId]['matches'][] = $result;
        }

        if ($byCompetition === []) {
            return [];
        }

        // 2. Équipe représentative par compétition.
        foreach ($byCompetition as $compId => &$comp) {
            $comp['info'] = $this->majorityInfo($comp['infos']);
        }
        unset($comp);

        // 3. Placement + playoffs par compétition.
        $entries = [];

        foreach ($byCompetition as $compId => $comp) {
            $info = $comp['info'];
            $placement = $this->resolvePlacement($compId, (int) $info['team_id']);
            [$playoffRound, $wonPlayoff] = $this->bestPlayoffRound($comp['matches']);

            // Inférence du podium depuis une finale si les tables n'ont pas d'ach.
            if ($placement === null && $playoffRound !== null) {
                if ($wonPlayoff && in_array($playoffRound, ['Grand Final', 'Final'], true)) {
                    $placement = 1;
                } elseif (! $wonPlayoff && in_array($playoffRound, ['Grand Final', 'Final'], true)) {
                    $placement = 2;
                }
            }

            if ($placement === null && $playoffRound === null) {
                continue;
            }

            $isNationsCup = in_array((string) $info['competition_category'], (array) config('palmares.categories.nations_cup'), true)
                || stripos((string) $info['competition_name'], 'Nations Cup') !== false;

            if ($isNationsCup && $placement === null) {
                // Nations Cup : pas de tables, on exige au moins un quart de finale.
                $prestigeMap = array_flip(self::PLAYOFF_PRESTIGE);
                $roundPrestige = $prestigeMap[$playoffRound] ?? count(self::PLAYOFF_PRESTIGE);
                $minPrestige = $prestigeMap['Quarter-final'] ?? count(self::PLAYOFF_PRESTIGE);

                if ($roundPrestige > $minPrestige) {
                    continue;
                }
            }

            // Timestamp max des matches (tri chronologique).
            $seasonTime = 0;
            foreach ($comp['matches'] as $match) {
                $t = (int) ($match['time'] ?? 0);
                if ($t > $seasonTime) {
                    $seasonTime = $t;
                }
            }

            $entries[] = [
                'competition_id' => $compId,
                'game_mode' => $info['game_mode'],
                'competition_name' => $info['competition_name'],
                'team_name' => $info['team_name'],
                'team_id' => (int) $info['team_id'],
                'division_name' => $info['division_name'],
                'placement' => $placement,
                'playoff_round' => $playoffRound,
                'won_playoff' => $wonPlayoff,
                'season_time' => $seasonTime,
            ];
        }

        // 4. Dédupliquer par saison logique, 5. décorer.
        return $this->decorateEntries($this->deduplicateBySeason($entries));
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<int, array<string, mixed>>
     */
    private function decorateEntries(array $entries): array
    {
        $medals = (array) config('palmares.medals');

        foreach ($entries as &$entry) {
            $entry['format'] = $entry['game_mode'];
            $entry['medal'] = isset($entry['placement'], $medals[$entry['placement']])
                ? (string) $medals[$entry['placement']]
                : null;
            unset($entry['game_mode']);
        }

        return $entries;
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<int, array<string, mixed>>
     */
    private function deduplicateBySeason(array $entries): array
    {
        if ($entries === []) {
            return [];
        }

        $grouped = [];
        foreach ($entries as $entry) {
            $key = $this->seasonKey((string) $entry['game_mode'], (string) $entry['competition_name']);
            $grouped[$key][] = $entry;
        }

        $result = [];
        foreach ($grouped as $group) {
            if (count($group) === 1) {
                $result[] = $group[0];

                continue;
            }
            array_push($result, ...$this->mergeSeasonEntries($group));
        }

        return $result;
    }

    /**
     * Fusionne les entrées d'une même saison logique (saison régulière +
     * playoffs séparés). Les playoffs déterminent le classement final ; la
     * médaille n'existant que sur la saison régulière est reportée.
     *
     * @param  array<int, array<string, mixed>>  $group
     * @return array<int, array<string, mixed>>
     */
    private function mergeSeasonEntries(array $group): array
    {
        $prestigeMap = array_flip(self::PLAYOFF_PRESTIGE);

        $playoffs = [];
        $regular = [];
        foreach ($group as $entry) {
            if (stripos((string) $entry['competition_name'], 'playoffs') !== false) {
                $playoffs[] = $entry;
            } else {
                $regular[] = $entry;
            }
        }

        $bestPlace = null;
        $maxTime = 0;
        foreach ($group as $entry) {
            $maxTime = max($maxTime, (int) $entry['season_time']);
            if ($entry['placement'] !== null && ($bestPlace === null || $entry['placement'] < $bestPlace)) {
                $bestPlace = $entry['placement'];
            }
        }

        if ($playoffs !== []) {
            $won = [];
            foreach ($playoffs as $playoff) {
                $out = $playoff;
                if ($out['placement'] === null) {
                    $out['placement'] = $bestPlace;
                }
                $out['season_time'] = $maxTime;
                $won[] = $out;
            }

            return $won;
        }

        // Pas de playoffs séparés : plusieurs podiums distincts sont conservés.
        $podium = array_values(array_filter($regular, static fn (array $e): bool => $e['placement'] !== null));
        if (count($podium) >= 2) {
            return $podium;
        }
        if (count($regular) === 1) {
            return $regular;
        }

        $bestPlaceEntry = null;
        $bestRound = null;
        $bestRoundEntry = null;
        $bestPrestige = PHP_INT_MAX;

        foreach ($regular as $entry) {
            if ($entry['placement'] !== null && ($bestPlaceEntry === null || $entry['placement'] < $bestPlaceEntry['placement'])) {
                $bestPlaceEntry = $entry;
            }

            if ($entry['playoff_round'] !== null) {
                $prestige = $prestigeMap[$entry['playoff_round']] ?? count(self::PLAYOFF_PRESTIGE);
                if ($prestige < $bestPrestige) {
                    $bestPrestige = $prestige;
                    $bestRound = $entry['playoff_round'];
                    $bestRoundEntry = $entry;
                }
            }
        }

        $source = $bestPlaceEntry ?? $bestRoundEntry ?? $regular[0];
        $merged = $source;
        $merged['placement'] = $bestPlace;
        $merged['playoff_round'] = $bestRound;
        $merged['won_playoff'] = $bestRoundEntry !== null
            ? (bool) $bestRoundEntry['won_playoff']
            : (bool) ($source['won_playoff'] ?? false);
        $merged['season_time'] = $maxTime;

        return [$merged];
    }

    /**
     * Clé de saison normalisée pour détecter les doublons
     * ("6v6 Season 50 (Autumn 2025)" et "...: Division 3 Playoffs").
     */
    private function seasonKey(string $gameMode, string $competitionName): string
    {
        $name = preg_replace('/\s*\([^)]*\)/u', '', $competitionName) ?? $competitionName;
        $name = preg_replace('/\s*:.*$/u', '', $name) ?? $name;
        $name = trim($name);

        return $gameMode.'|'.$name;
    }
}
