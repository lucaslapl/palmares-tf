<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AdminDashboardRepository;
use App\Models\CommandRunRepository;
use Illuminate\View\View;

/**
 * Dashboard de monitoring (lecture seule) : vue d'ensemble du pipeline,
 * santé du scheduler, historique des runs, fraîcheur des JSON publics et
 * qualité des données.
 */
final class AdminDashboardController extends Controller
{
    public function index(AdminDashboardRepository $repo, CommandRunRepository $runs): View
    {
        return view('admin.dashboard', [
            'overview' => $repo->overview(),
            'schedules' => $repo->scheduleHealth(),
            'runs' => $runs->recent(20),
            'jsonFiles' => $repo->jsonFiles(),
            'quality' => $repo->qualityIssues(),
            'api' => $repo->apiMetrics(),
            'autoRefresh' => 60,
        ]);
    }
}
