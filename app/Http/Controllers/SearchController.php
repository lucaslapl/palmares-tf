<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Palmares\LeaderboardBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

final class SearchController extends Controller
{
    public function index(Request $request, LeaderboardBuilder $builder): View
    {
        $q = trim((string) $request->query('q'));

        $results = [];
        if ($q !== '') {
            $payload = $builder->readPlayersIndex();
            $results = $this->filterPlayers((array) ($payload['players'] ?? []), $q, 50);
        }

        return view('search', ['results' => $results]);
    }

    /**
     * Autocomplétion sur l'index de recherche (fichier JSON auto-régénéré).
     */
    public function autocomplete(Request $request, LeaderboardBuilder $builder): JsonResponse
    {
        $q = trim((string) $request->query('q'));

        if ($q === '') {
            return response()->json(['players' => []]);
        }

        $payload = $builder->readPlayersIndex();

        return response()->json([
            'players' => $this->filterPlayers((array) ($payload['players'] ?? []), $q, 20),
        ]);
    }

    /**
     * Filtre l'index par nom (insensible à la casse), avec URL de profil.
     *
     * @param  array<int, array<string, mixed>>  $players
     * @return array<int, array<string, mixed>>
     */
    private function filterPlayers(array $players, string $q, int $limit): array
    {
        $needle = Str::lower($q);
        $matches = [];

        foreach ($players as $player) {
            if (! str_contains(Str::lower((string) $player['name']), $needle)) {
                continue;
            }

            $matches[] = [
                'etf2l_id' => (int) ($player['etf2l_id'] ?? 0),
                'name' => (string) ($player['name'] ?? ''),
                'country' => (string) ($player['country'] ?? ''),
                'flag' => country_flag_url((string) ($player['country'] ?? '')),
                'url' => route('player.show', ['id' => (string) ($player['etf2l_id'] ?? 0)]),
            ];

            if (count($matches) >= $limit) {
                break;
            }
        }

        return $matches;
    }
}
