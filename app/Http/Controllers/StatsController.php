<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Palmares\StatsBuilder;
use Illuminate\View\View;

/**
 * Page des statistiques communautaires (/stats) : séries temporelles de
 * l'activité ETF2L (saisons, équipes, joueurs) dessinées côté client en
 * Chart.js à partir du JSON pré-calculé stats.json.
 */
final class StatsController extends Controller
{
    public function index(StatsBuilder $builder): View
    {
        return view('stats', [
            'stats' => $builder->read(),
        ]);
    }
}
