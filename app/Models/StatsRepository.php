<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Facades\DB;

/**
 * Accès agrégé pour les statistiques communautaires (page /stats).
 *
 * Contrairement aux leaderboards (agrégats par joueur, médailles uniquement),
 * les stats agrègent les participations par saison logique : joueurs ayant
 * joué au moins un match, équipes classées par saison — l'activité réelle de
 * la ligue, pas seulement les exploits.
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
     * Participations des joueurs (une ligne = un joueur × une compétition
     * où il a joué au moins un match), avec la saison rattachée pour
     * regrouper par saison logique.
     *
     * @return array<int, object> lignes participations enrichies du nom de saison
     */
    public function participations(): array
    {
        return DB::table('participations')
            ->leftJoin('seasons', 'seasons.id', '=', 'participations.season_id')
            ->select([
                'participations.player_id',
                'participations.season_id',
                'participations.competition_id',
                'participations.format',
                'participations.competition_name',
                'participations.season_time',
                'seasons.name AS season_name',
                'seasons.category AS season_category',
                'seasons.format AS season_format',
            ])
            ->get()
            ->all();
    }

    /**
     * Nombre de joueurs distincts ayant au moins une médaille
     * (or/argent/bronze), pour distinguer « participants » et « médaillés »
     * dans le résumé de la page.
     */
    public function medalPlayersCount(): int
    {
        return (int) DB::table('palmares')
            ->whereNotNull('medal')
            ->distinct()
            ->count('player_id');
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
