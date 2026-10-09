<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SeasonGroupRepository;
use App\Services\Palmares\LeaderboardBuilder;
use Illuminate\View\View;

final class HomeController extends Controller
{
    public function index(LeaderboardBuilder $builder, SeasonGroupRepository $seasonGroups): View
    {
        $payload = $builder->readLeaderboard(null, min(24 * 3600, 3600));
        $players = $payload['players'] ?? [];

        return view('home', [
            'topPlayers' => array_slice($players, 0, 10),
            'playerTotal' => count($players),
            // Saisons unifiées : toutes les divisions d'une même saison
            // d'ETF2L comptent pour une seule saison.
            'seasonsCount' => $seasonGroups->count(),
        ]);
    }
}
