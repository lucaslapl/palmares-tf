<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Facades\DB;

/**
 * Accès aux participations des joueurs (table participations) : une ligne =
 * un joueur × une compétition de ligue ou de Nations Cup où il a joué au
 * moins un match. Contrairement au palmares, aucune condition d'exploit :
 * c'est la base des statistiques d'activité (tous les participants, pas
 * seulement les médaillés ou les équipes de playoffs).
 */
final class ParticipationsRepository
{
    /**
     * Remplace l'ensemble des participations d'un joueur (mise à jour atomique).
     *
     * @param  array<int, array<string, mixed>>  $rows  participations calculées
     */
    public function replaceForPlayer(int $playerId, array $rows): void
    {
        DB::transaction(function () use ($playerId, $rows): void {
            DB::table('participations')->where('player_id', $playerId)->delete();

            $insert = [];
            foreach ($rows as $row) {
                $competitionId = (int) ($row['competition_id'] ?? 0);

                $insert[] = [
                    'player_id' => $playerId,
                    'season_id' => $this->resolveSeasonId($competitionId),
                    'competition_id' => $competitionId > 0 ? $competitionId : null,
                    'team_id' => $row['team_id'] !== null ? $this->resolveTeamId((int) $row['team_id']) : null,
                    'format' => $row['format'] ?? null,
                    'competition_name' => $row['competition_name'] ?? null,
                    'team_name' => $row['team_name'] ?? null,
                    'division_name' => $row['division_name'] ?? null,
                    'season_time' => $row['season_time'] ?? null,
                ];
            }

            if ($insert !== []) {
                DB::table('participations')->insert($insert);
            }
        });
    }

    /**
     * Saison rattachée à une compétition ETF2L (null hors ligue, ex. Nations Cup).
     */
    private function resolveSeasonId(int $competitionId): ?int
    {
        if ($competitionId <= 0) {
            return null;
        }

        return (int) DB::table('seasons')
            ->where('etf2l_competition_id', $competitionId)
            ->value('id') ?: null;
    }

    /**
     * Identifiant interne d'une équipe ETF2L (null si inconnue).
     */
    private function resolveTeamId(int $etf2lTeamId): ?int
    {
        if ($etf2lTeamId <= 0) {
            return null;
        }

        return (int) DB::table('teams')
            ->where('etf2l_team_id', $etf2lTeamId)
            ->value('id') ?: null;
    }
}
