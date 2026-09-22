<?php

declare(strict_types=1);

namespace App\Services\Palmares;

use App\Models\PlayersRepository;
use App\Models\TeamsRepository;
use App\Services\Etf2l\Etf2lApiClient;

/**
 * Récolte les joueurs candidats depuis les équipes connues (app:harvest-players).
 *
 * Pour chaque équipe ingérée (donc chaque équipe classée en table), on reprend
 * le roster actuel ET l'historique des transferts. La vérité finale reste
 * calculée joueur par joueur (app:compute-palmares), cette récolte ne sert
 * qu'à constituer un vivier de joueurs à traiter.
 */
final class RosterHarvester
{
    public function __construct(
        private readonly Etf2lApiClient $client,
        private readonly TeamsRepository $teams,
        private readonly PlayersRepository $players,
    ) {}

    /**
     * @return int nombre de nouveaux joueurs découverts
     */
    public function run(?callable $progress = null): int
    {
        $candidates = [];

        foreach ($this->teams->allEtf2lIds() as $teamId) {
            $team = $this->client->team($teamId);
            if ($team === []) {
                continue;
            }

            foreach (($team['players'] ?? []) as $player) {
                $this->pushCandidate($candidates, $player);
            }

            // Historique des transferts (toutes pages).
            $page = 1;
            do {
                $payload = $this->client->transfersPage($teamId, $page);

                foreach (($payload['data'] ?? []) as $transfer) {
                    $who = $transfer['who'] ?? null;
                    if (is_array($who)) {
                        $this->pushCandidate($candidates, $who);
                    }
                }

                if ($page >= $this->client->lastPage($payload)) {
                    break;
                }
                $page++;
            } while (true);

            if ($progress !== null) {
                $progress($team);
            }
        }

        $inserted = $this->players->insertCandidates(array_values($candidates));

        return $inserted;
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @param  array<string, mixed>  $player
     */
    private function pushCandidate(array &$candidates, array $player): void
    {
        $etf2lId = (int) ($player['id'] ?? 0);
        if ($etf2lId <= 0) {
            return;
        }

        $steam = $player['steam'] ?? null;
        $candidates[$etf2lId] = [
            'etf2l_id' => $etf2lId,
            'name' => (string) ($player['name'] ?? ''),
            'country' => (string) ($player['country'] ?? ''),
            'steam_id64' => is_array($steam) ? (string) ($steam['id64'] ?? '') : '',
            'avatar' => is_array($steam) ? (string) ($steam['avatar'] ?? '') : '',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
