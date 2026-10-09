<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SeasonGroupRepository;
use App\Models\SeasonsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class SeasonController extends Controller
{
    /**
     * Rangs de prestige des divisions nommées (0 = la plus prestigieuse).
     * Les divisions numérotées des anciennes saisons sont intercalées par
     * divisionRank() : un numéro plus petit = niveau plus haut.
     */
    private const DIVISION_RANK = [
        'premiership' => 0,
        'premier division' => 0,
        'top division' => 0,
        'top tiers' => 0,
        'high' => 10,
        'mid' => 20,
        'middle' => 20,
        'low' => 30,
        'open' => 40,
        'fresh' => 50,
    ];

    public function index(SeasonGroupRepository $seasonGroups): View
    {
        $formats = (array) config('palmares.formats');

        // Lister les groupes de saisons (saisons unifiées)
        $allGroups = $seasonGroups->listWithCompetitions();

        $byFormat = ['6s' => [], '9v9' => []];
        foreach ($allGroups as $groupId => $data) {
            $group = $data['group'];
            $format = (string) $group->format;

            // Filtrer les compétitions satellites (playoffs, 3e place, qualifications).
            $validCompetitions = array_values(array_filter(
                $data['competitions'],
                static fn (object $comp): bool => ! SeasonsRepository::isPlayoffCompetition((string) $comp->name),
            ));

            if ($validCompetitions === []) {
                continue;
            }

            // Le groupe est « live » tant qu'une division est en cours.
            $allArchived = array_reduce(
                $validCompetitions,
                static fn (bool $carry, object $comp): bool => $carry && (bool) $comp->archived,
                true,
            );

            $byFormat[$format][] = (object) [
                'id' => $groupId,
                'name' => $group->name,
                'format' => $group->format,
                'season_number' => $group->season_number,
                'competitions' => $validCompetitions,
                'competition_count' => count($validCompetitions),
                'archived' => $allArchived,
                'etf2l_competition_id' => $this->archiveCompetitionId($group, $validCompetitions),
            ];
        }

        return view('seasons', [
            'byFormat' => $byFormat,
            'formats' => $formats,
        ]);
    }

    public function show(int $seasonId, SeasonGroupRepository $seasonGroups, SeasonsRepository $seasons): View
    {
        $group = $seasonGroups->find($seasonId);

        // Vérifier si c'est un groupe de saison ou une ancienne saison sans groupe
        if ($group === null) {
            // Tentative de fallback : vérifier si c'est un ID de saison (table seasons)
            $season = $seasons->find($seasonId);
            if ($season !== null && ! SeasonsRepository::isPlayoffCompetition((string) $season->name)) {
                // C'est une saison sans groupe, vérifier si elle a un season_group_id
                if ($season->season_group_id !== null) {
                    // Rediriger vers le groupe
                    $group = $seasonGroups->find((int) $season->season_group_id);
                    if ($group !== null) {
                        return $this->showGroup($group);
                    }
                }

                return $this->showLegacySeason($season);
            }
            throw new NotFoundHttpException('Saison introuvable.');
        }

        return $this->showGroup($group);
    }

    /**
     * Affiche un groupe de saison avec toutes ses compétitions.
     */
    private function showGroup(object $group): View
    {
        // Trouver toutes les compétitions du groupe
        $competitions = DB::table('seasons')
            ->where('season_group_id', $group->id)
            ->orderBy('name')
            ->get()
            ->all();

        $validCompetitions = array_values(array_filter(
            $competitions,
            static fn (object $comp): bool => ! SeasonsRepository::isPlayoffCompetition((string) $comp->name),
        ));

        if ($validCompetitions === []) {
            throw new NotFoundHttpException('Saison introuvable.');
        }

        // Récupérer toutes les tables pour toutes les compétitions du groupe
        $allRows = collect();
        foreach ($validCompetitions as $comp) {
            $rows = DB::table('season_teams')
                ->join('teams', 'teams.id', '=', 'season_teams.team_id')
                ->where('season_teams.season_id', $comp->id)
                ->select([
                    'season_teams.season_id',
                    'season_teams.division_name',
                    'season_teams.ach',
                    'season_teams.medal',
                    'teams.name as team_name',
                    'teams.country',
                    'teams.etf2l_team_id',
                ])
                ->get();

            $allRows = $allRows->concat($rows);
        }

        // Regrouper par division : podiums en tête dans chaque division,
        // divisions triées par prestige (divisionRank).
        $divisions = $allRows
            ->groupBy(fn (object $row): string => (string) $row->division_name)
            ->map(function ($rows): array {
                $sorted = $rows->values()->all();
                usort($sorted, static function (object $a, object $b): int {
                    if ($a->ach === null && $b->ach === null) {
                        return 0;
                    }
                    if ($a->ach === null) {
                        return 1;
                    }
                    if ($b->ach === null) {
                        return -1;
                    }

                    return $a->ach <=> $b->ach;
                });

                return $sorted;
            })
            ->sortBy(fn (array $rows, string $division): int => $this->divisionRank($division));

        $allArchived = array_reduce(
            $validCompetitions,
            static fn (bool $carry, object $comp): bool => $carry && (bool) $comp->archived,
            true,
        );

        $formats = (array) config('palmares.formats');

        return view('season', [
            'season' => (object) [
                'id' => $group->id,
                'name' => $group->name,
                'format' => $group->format,
                'season_number' => $group->season_number,
                'archived' => $allArchived,
                'etf2l_competition_id' => $this->archiveCompetitionId($group, $validCompetitions),
            ],
            'format' => $formats[(string) $group->format]['label'] ?? (string) $group->format,
            'divisions' => $divisions,
            'crowds' => $divisions->mapWithKeys(fn (array $rows, string $division): array => [$division => count($rows)]),
            'competitions' => $validCompetitions,
        ]);
    }

    /**
     * ID de compétition ETF2L servant de lien vers les archives du groupe :
     * la plus petite compétition valide du groupe, en repli sur les bornes
     * stockées dans season_groups.
     *
     * @param  array<int, object>  $competitions
     */
    private function archiveCompetitionId(object $group, array $competitions): int
    {
        $ids = array_map(static fn (object $comp): int => (int) $comp->etf2l_competition_id, $competitions);
        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));

        if ($ids !== []) {
            return min($ids);
        }

        return (int) ($group->min_competition_id ?? 0) > 0 ? (int) $group->min_competition_id : 0;
    }

    /**
     * Affichage d'une saison sans groupe (fallback pour compatibilité).
     */
    private function showLegacySeason(object $season): View
    {
        // Regroupement par division, podiums en tête.
        $rows = DB::table('season_teams')
            ->join('teams', 'teams.id', '=', 'season_teams.team_id')
            ->where('season_teams.season_id', $season->id)
            ->select([
                'season_teams.division_name',
                'season_teams.ach',
                'season_teams.medal',
                'teams.name',
                'teams.country',
                'teams.etf2l_team_id',
            ])
            ->orderBy('season_teams.division_name')
            ->orderByRaw('CASE WHEN season_teams.ach IS NULL THEN 1 ELSE 0 END, season_teams.ach')
            ->get()
            ->sortBy(fn (object $row): int => $this->divisionRank((string) $row->division_name))
            ->values();

        $divisions = $rows->groupBy(fn ($row): string => (string) $row->division_name);
        $formats = (array) config('palmares.formats');

        return view('season', [
            'season' => $season,
            'format' => $formats[(string) $season->format]['label'] ?? (string) $season->format,
            'divisions' => $divisions,
            'crowds' => $divisions->mapWithKeys(fn ($group, string $division): array => [$division => count($group)]),
        ]);
    }

    /**
     * Rang de tri d'une division : les paliers nommés d'abord, puis les
     * divisions numérotées ("Division 1", "Division 2 & 3", ...).
     *
     * @return int en plus petit rang = plus prestigieux
     */
    private function divisionRank(string $division): int
    {
        $key = strtolower(trim($division));
        if (isset(self::DIVISION_RANK[$key])) {
            return self::DIVISION_RANK[$key];
        }

        if (preg_match('/division\s+(\d+)/i', $division, $m) === 1) {
            return 5 + 5 * (int) $m[1];
        }
        if (preg_match('/tier\s+(\d+)/i', $division, $m) === 1) {
            return 5 + 5 * (int) $m[1];
        }

        return PHP_INT_MAX;
    }
}
