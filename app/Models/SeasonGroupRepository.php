<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Facades\DB;

/**
 * Accès aux groupes de saisons (saisons logiques unifiées).
 *
 * Un groupe de saison regroupe toutes les divisions d'une même saison ETF2L.
 * Par exemple : "6v6 Season 52 Division 1", "6v6 Season 52 Division 2" → "6v6 Season 52".
 */
final class SeasonGroupRepository
{
    /**
     * Crée ou trouve un groupe de saison à partir du format et du numéro.
     *
     * @param  string  $format  Format (6s ou 9v9)
     * @param  int  $seasonNumber  Numéro de saison
     * @return int ID du groupe de saison
     */
    public function findOrCreate(string $format, int $seasonNumber): int
    {
        $name = SeasonsRepository::seasonGroupName($format, $seasonNumber);

        $group = DB::table('season_groups')
            ->where('format', $format)
            ->where('season_number', $seasonNumber)
            ->first();

        if ($group !== null) {
            return (int) $group->id;
        }

        return (int) DB::table('season_groups')->insertGetId([
            'format' => $format,
            'season_number' => $seasonNumber,
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Met à jour les bornes des IDs de compétition pour un groupe.
     *
     * @param  int  $groupId  ID du groupe de saison
     * @param  int  $competitionId  ID ETF2L de la compétition à ajouter
     */
    public function updateCompetitionRange(int $groupId, int $competitionId): void
    {
        $group = DB::table('season_groups')->where('id', $groupId)->first();
        if ($group === null) {
            return;
        }

        $minId = $group->min_competition_id ?? $competitionId;
        $maxId = $group->max_competition_id ?? $competitionId;

        if ($competitionId < $minId) {
            $minId = $competitionId;
        }
        if ($competitionId > $maxId) {
            $maxId = $competitionId;
        }

        DB::table('season_groups')
            ->where('id', $groupId)
            ->update([
                'min_competition_id' => $minId,
                'max_competition_id' => $maxId,
                'updated_at' => now(),
            ]);
    }

    /**
     * Liste tous les groupes de saisons, triés par numéro décroissant.
     *
     * @return array<int, object>
     */
    public function listAll(?string $format = null): array
    {
        $query = DB::table('season_groups')
            ->orderByDesc('season_number');

        if ($format !== null) {
            $query->where('format', $format);
        }

        return $query->get()->all();
    }

    /**
     * Trouve un groupe de saison par son ID.
     */
    public function find(int $id): ?object
    {
        return DB::table('season_groups')->where('id', $id)->first();
    }

    /**
     * Liste les groupes de saisons avec leurs compétitions associées.
     *
     * @return array<int, array{group: object, competitions: array<int, object>}>
     */
    public function listWithCompetitions(?string $format = null): array
    {
        $groups = $this->listAll($format);
        $result = [];

        foreach ($groups as $group) {
            $competitions = DB::table('seasons')
                ->where('season_group_id', $group->id)
                ->orderBy('name')
                ->get()
                ->all();

            $result[(int) $group->id] = [
                'group' => $group,
                'competitions' => $competitions,
            ];
        }

        return $result;
    }

    /**
     * Compte le nombre de groupes de saisons.
     */
    public function count(): int
    {
        return (int) DB::table('season_groups')->count();
    }

    /**
     * Supprime tous les groupes de saisons et réinitialise les liens.
     * Utilisé pour une reconstruction complète.
     */
    public function resetAll(): void
    {
        DB::table('seasons')->update(['season_group_id' => null]);
        DB::table('palmares')->update(['season_group_id' => null]);
        DB::table('season_groups')->delete();
    }
}
