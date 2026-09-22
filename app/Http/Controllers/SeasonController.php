<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SeasonsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class SeasonController extends Controller
{
    public function index(SeasonsRepository $seasons): View
    {
        $all = array_values(array_filter(
            $seasons->listAll(),
            static fn (object $season): bool => ! SeasonsRepository::isPlayoffCompetition((string) $season->name),
        ));
        $formats = (array) config('palmares.formats');

        $byFormat = ['6s' => [], '9v9' => []];
        foreach ($all as $season) {
            $format = (string) $season->format;
            $byFormat[$format][] = $season;
        }

        return view('seasons', [
            'byFormat' => $byFormat,
            'formats' => $formats,
        ]);
    }

    public function show(int $season, SeasonsRepository $seasons): View
    {
        $season = $seasons->find($season);

        // Les sous-compétitions (playoffs, 3e place, qualifications) ne sont
        // pas exposées comme des saisons : pas de table de classement à montrer.
        if ($season === null || SeasonsRepository::isPlayoffCompetition((string) $season->name)) {
            throw new NotFoundHttpException('Saison introuvable.');
        }

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
            ->get();

        $divisions = $rows->groupBy(fn ($row): string => (string) $row->division_name);
        $formats = (array) config('palmares.formats');

        return view('season', [
            'season' => $season,
            'format' => $formats[(string) $season->format]['label'] ?? (string) $season->format,
            'divisions' => $divisions,
            'crowds' => $divisions->mapWithKeys(fn ($group, string $division): array => [$division => count($group)]),
        ]);
    }
}
