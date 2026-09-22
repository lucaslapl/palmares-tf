<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Facades\DB;

/**
 * Accès au palmarès calculé des joueurs (table palmares).
 */
final class PalmaresRepository
{
    /**
     * Remplace l'intégralité du palmarès d'un joueur (mise à jour atomique).
     *
     * @param  array<int, array<string, mixed>>  $entries
     */
    public function replaceForPlayer(int $playerId, array $entries): void
    {
        DB::transaction(function () use ($playerId, $entries): void {
            DB::table('palmares')->where('player_id', $playerId)->delete();

            $rows = [];
            foreach ($entries as $e) {
                $seasonId = $this->resolveSeasonId((int) ($e['competition_id'] ?? 0));

                $rows[] = [
                    'player_id' => $playerId,
                    'season_id' => $seasonId,
                    'competition_id' => (int) ($e['competition_id'] ?? 0) > 0 ? (int) $e['competition_id'] : null,
                    'team_id' => $e['team_id'] !== null ? $this->resolveTeamId((int) $e['team_id']) : null,
                    'format' => $e['format'] ?? null,
                    'competition_name' => $e['competition_name'] ?? null,
                    'team_name' => $e['team_name'] ?? null,
                    'division_name' => $e['division_name'] ?? null,
                    'placement' => $e['placement'] ?? null,
                    'medal' => $e['medal'] ?? null,
                    'playoff_round' => $e['playoff_round'] ?? null,
                    'won_playoff' => ($e['won_playoff'] ?? false) ? 1 : 0,
                    'season_time' => $e['season_time'] ?? null,
                ];
            }

            if ($rows !== []) {
                DB::table('palmares')->insert($rows);
            }
        });
    }

    private function resolveSeasonId(int $competitionId): ?int
    {
        if ($competitionId <= 0) {
            return null;
        }

        return (int) DB::table('seasons')
            ->where('etf2l_competition_id', $competitionId)
            ->value('id') ?: null;
    }

    private function resolveTeamId(int $etf2lTeamId): ?int
    {
        if ($etf2lTeamId <= 0) {
            return null;
        }

        return (int) DB::table('teams')
            ->where('etf2l_team_id', $etf2lTeamId)
            ->value('id') ?: null;
    }

    public function awardsForPlayer(int $playerId): array
    {
        return DB::table('palmares')
            ->where('player_id', $playerId)
            ->orderByDesc('season_time')
            ->get()
            ->all();
    }

    /**
     * Totaux par médaille et points d'un joueur.
     *
     * @return array<string, int>
     */
    public function totalsForPlayer(int $playerId): array
    {
        $totals = array_fill_keys(['gold', 'silver', 'bronze', 'awards', 'points'], 0);

        $rows = DB::table('palmares')
            ->select(['medal', 'placement'])
            ->where('player_id', $playerId)
            ->whereNotNull('medal')
            ->get();

        $weights = (array) config('palmares.weights');

        foreach ($rows as $row) {
            $medal = (string) $row->medal;
            if (isset($totals[$medal])) {
                $totals[$medal]++;
                $totals['awards']++;
                $totals['points'] += (int) ($weights[$medal] ?? 0);
            }
        }

        $totals['points'] = $totals['points'] > 0 ? $totals['points'] : 0;

        return $totals;
    }

    /**
     * Agréger les totaux de tous les joueurs ayant au moins une médaille ou
     * une participation retenue, pour construire les leaderboards.
     *
     * @return array<int, object>
     */
    public function aggregateByPlayer(?string $format = null): array
    {
        $query = DB::table('palmares')
            ->join('players', 'players.id', '=', 'palmares.player_id')
            ->select([
                'players.id',
                'players.etf2l_id',
                'players.name',
                'players.country',
                'players.steam_id64',
                'players.avatar',
                DB::raw('SUM(CASE palmares.medal WHEN \'gold\' THEN 1 ELSE 0 END) AS golds'),
                DB::raw('SUM(CASE palmares.medal WHEN \'silver\' THEN 1 ELSE 0 END) AS silvers'),
                DB::raw('SUM(CASE palmares.medal WHEN \'bronze\' THEN 1 ELSE 0 END) AS bronzes'),
                DB::raw('COUNT(palmares.id) AS awards'),
                DB::raw('SUM(CASE palmares.medal
                    WHEN \'gold\' THEN '.(int) config('palmares.weights.gold').'
                    WHEN \'silver\' THEN '.(int) config('palmares.weights.silver').'
                    WHEN \'bronze\' THEN '.(int) config('palmares.weights.bronze').'
                    ELSE 0 END) AS points'),
            ])
            ->whereNotNull('palmares.medal');

        if ($format !== null) {
            $query->where('palmares.format', $format);
        }

        return $query
            // MySQL (sql_mode strict) exige toutes les colonnes non agrégées
            // dans le GROUP BY, contrairement à SQLite : on liste explicitement
            // chaque colonne de players pour être compatible avec les deux.
            ->groupBy([
                'players.id',
                'players.etf2l_id',
                'players.name',
                'players.country',
                'players.steam_id64',
                'players.avatar',
            ])
            ->get()
            ->all();
    }
}
