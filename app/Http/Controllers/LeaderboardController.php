<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Palmares\LeaderboardBuilder;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class LeaderboardController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request, LeaderboardBuilder $builder): View
    {
        $requested = (string) $request->query('format', 'all');
        $format = match ($requested) {
            '6v6' => '6s',
            '9v9' => '9v9',
            default => null,
        };

        $payload = $builder->readLeaderboard($format);
        $players = $payload['players'] ?? [];

        $page = (int) max(1, (int) $request->query('page', 1));
        $total = count($players);
        $pages = (int) ceil($total / self::PER_PAGE);
        $offset = ($page - 1) * self::PER_PAGE;

        return view('leaderboard', [
            'players' => array_slice($players, $offset, self::PER_PAGE),
            'format' => $requested,
            'page' => $page,
            'pages' => max(1, $pages),
            'total' => $total,
            'perPage' => self::PER_PAGE,
        ]);
    }
}
