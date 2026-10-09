<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Facades\DB;

/**
 * Accès agrégé pour les statistiques communautaires (page /stats).
 *
 * Contrairement aux leaderboards (agrégats par joueur), les stats agrègent la
 * table palmares par saison logique : joueurs actifs par saison, nouvelles
 * recrues vs joueurs qui reviennent, équipes classées par saison.
 */
final class StatsRepository
{
    /**
     * Saisons avec comptages agrégés (équipes et divisions distinctes).
     *
     * @return array<int, object> lignes season_teams agrégées par season_id
     */
    public function teamCountsBySeason(): array
    {
        return DB::table('season_teams')
            ->select('season_id')
            ->selectRaw('COUNT(DISTINCT team_id) AS teams')
            ->selectRaw('COUNT(DISTINCT division_name) AS divisions')
            ->whereNotNull('team_id')
            ->groupBy('season_id')
            ->get()
            ->all();
    }

    /**
     * Participations du palmarès (une ligne = un joueur × une compétition
     * retenue), avec la saison rattachée pour regrouper par saison logique.
     *
     * @return array<int, object> lignes palmares enrichies du nom de saison
     */
    public function participations(): array
    {
        return DB::table('palmares')
            ->leftJoin('seasons', 'seasons.id', '=', 'palmares.season_id')
            ->select([
                'palmares.player_id',
                'palmares.season_id',
                'palmares.competition_id',
                'palmares.format',
                'palmares.competition_name',
                'palmares.season_time',
                'seasons.name AS season_name',
                'seasons.category AS season_category',
                'seasons.format AS season_format',
            ])
            ->get()
            ->all();
    }

    /**
     * Toutes les saisons de ligue (compétitions ingérées), pour le comptage
     * annuel et l'appartenance à une saison logique.
     *
     * @return array<int, object>
     */
    public function leagueSeasons(): array
    {
        return DB::table('seasons')
            ->select(['id', 'etf2l_competition_id', 'name', 'category', 'format', 'archived'])
            ->get()
            ->all();
    }
}
