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
     * Les saisons anciennes nommées par année (« ETF2L AFS Season 2010 »)
     * exposent le nom de leur compétition comme libellé du groupe au lieu
     * du libellé générique « 6v6 Season 2010 ».
     *
     * @param  string  $format  Format (6s ou 9v9)
     * @param  int  $seasonNumber  Numéro de saison
     * @param  string|null  $displayName  Libellé du groupe à la création, ou null
     * @return int ID du groupe de saison
     */
    public function findOrCreate(string $format, int $seasonNumber, ?string $displayName = null): int
    {
        $group = DB::table('season_groups')
            ->where('format', $format)
            ->where('season_number', $seasonNumber)
            ->first();

        if ($group !== null) {
            // Un groupe année créé avec le libellé générique (« 6v6 Season
            // 2010 ») est réparé au nom de sa compétition dès qu'on le connaît.
            if ($displayName !== null && $group->name !== $displayName) {
                DB::table('season_groups')
                    ->where('id', $group->id)
                    ->update(['name' => $displayName, 'updated_at' => now()]);
            }

            return (int) $group->id;
        }

        $name = $displayName ?? SeasonsRepository::seasonGroupName($format, $seasonNumber);

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
     * Liste les groupes de saisons avec leurs compétitions associées,
     * triés de la plus récente à la plus ancienne.
     *
     * L'ordre chronologique suit le plus grand id de compétition ETF2L du
     * groupe : les ids ETF2L croissent dans le temps, y compris pour les
     * saisons anciennes nommées par année (« Season 2010 ») dont le numéro
     * de saison ne reflète pas leur époque.
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
                'max_competition_id' => $this->maxCompetitionId($competitions),
            ];
        }

        uasort($result, static function (array $a, array $b): int {
            return $b['max_competition_id'] <=> $a['max_competition_id'];
        });

        return array_map(static function (array $entry): array {
            return ['group' => $entry['group'], 'competitions' => $entry['competitions']];
        }, $result);
    }

    /**
     * Plus grand id de compétition ETF2L d'un lot de compétitions (0 si vide).
     *
     * @param  array<int, object>  $competitions
     */
    private function maxCompetitionId(array $competitions): int
    {
        $max = 0;
        foreach ($competitions as $competition) {
            $id = (int) $competition->etf2l_competition_id;
            if ($id > $max) {
                $max = $id;
            }
        }

        return $max;
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
