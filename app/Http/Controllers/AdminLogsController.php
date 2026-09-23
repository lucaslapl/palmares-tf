<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AdminDashboardRepository;
use Illuminate\View\View;

/**
 * Journal du pipeline pour le panel admin : tail du log scheduler et des
 * lignes app:* de laravel.log (erreurs et avertissements surlignés).
 */
final class AdminLogsController extends Controller
{
    public function index(AdminDashboardRepository $repo): View
    {
        return view('admin.logs', [
            'scheduleLog' => $repo->tailScheduleLog(100),
            'scheduleLogExists' => is_file(storage_path('logs/schedule.log')),
            'appLog' => $repo->tailAppLog(120),
            'autoRefresh' => 60,
        ]);
    }
}
