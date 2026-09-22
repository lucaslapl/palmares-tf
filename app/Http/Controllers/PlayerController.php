<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PalmaresRepository;
use App\Models\PlayersRepository;
use App\Services\Etf2l\Etf2lApiClient;
use App\Services\Palmares\ComputePalmaresService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PlayerController extends Controller
{
    public function show(
        Request $request,
        int $id,
        PlayersRepository $players,
        PalmaresRepository $palmares,
        Etf2lApiClient $client,
        ComputePalmaresService $compute,
    ): View {
        // Résolution du joueur (création à la volée si inconnu).
        $player = $players->findByEtf2lId($id);

        if ($player === null) {
            $api = $client->player($id);
            if ($api === []) {
                throw new NotFoundHttpException('Joueur introuvable.');
            }
            $players->upsertFromApi($api);
            $player = $players->findByEtf2lId($id);
        }

        if ($player === null) {
            throw new NotFoundHttpException('Joueur introuvable.');
        }

        // Recalcul du palmarès si obsolète (profil : calcul synchrone, API
        // throttlée + table en cache en base => quelques secondes au plus).
        if ($players->isStale($player)) {
            $compute->computeForPlayer($id);
        }

        $entries = $palmares->awardsForPlayer((int) $player->id);
        $totals = $palmares->totalsForPlayer((int) $player->id);
        $formats = (array) config('palmares.formats');
        $medalLabels = ['gold' => 'Gold', 'silver' => 'Silver', 'bronze' => 'Bronze'];

        $awards = array_map(static function (object $row) use ($formats, $medalLabels): array {
            $format = (string) ($row->format ?? '');

            return [
                'format' => $formats[$format]['label'] ?? $format,
                'competition_name' => (string) $row->competition_name,
                'team_name' => (string) $row->team_name,
                'division_name' => (string) $row->division_name,
                'medal' => $row->medal !== null ? (string) $row->medal : null,
                'medal_label' => $row->medal !== null ? ($medalLabels[(string) $row->medal] ?? '') : null,
                'playoff_round' => $row->playoff_round !== null ? (string) $row->playoff_round : null,
                'placement' => $row->placement !== null ? (int) $row->placement : null,
                'season_time' => (int) ($row->season_time ?? 0),
            ];
        }, $entries);

        return view('player', [
            'player' => $player,
            'awards' => $awards,
            'totals' => $totals,
            'freshness' => (int) ($player->computed_at ?? 0),
        ]);
    }
}
