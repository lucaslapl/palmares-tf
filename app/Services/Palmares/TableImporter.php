<?php

declare(strict_types=1);

namespace App\Services\Palmares;

use App\Models\SeasonsRepository;
use App\Models\TeamsRepository;
use App\Services\Etf2l\Etf2lApiClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Importe les tables de classement final des saisons (app:sync-tables).
 *
 * Chaque entrée de table fournit le podium d'une division via le champ "ach"
 * (1 = or, 2 = argent, 3 = bronze). Les équipes sont enregistrées au passage
 * afin de servir de source pour la récolte des joueurs.
 */
final class TableImporter
{
    /**
     * Saisons en échec lors de la dernière exécution : elles restent « pending »
     * (ingested_at non posé) et seront retentées à la prochaine passe.
     *
     * @var list<string>
     */
    private array $errors = [];

    public function __construct(
        private readonly Etf2lApiClient $client,
        private readonly SeasonsRepository $seasons,
        private readonly TeamsRepository $teams,
    ) {}

    /**
     * @param  callable(object, int): void|null  $progress
     * @return int nombre de lignes de table insérées / mises à jour
     */
    public function run(bool $force = false, ?callable $progress = null, int $limit = 0): int
    {
        $pending = $force ? $this->seasons->listAll() : $this->seasons->pendingTables();
        if ($limit > 0) {
            $pending = array_slice($pending, 0, $limit);
        }

        $medals = (array) config('palmares.medals');
        $ingested = 0;
        $this->errors = [];

        foreach ($pending as $season) {
            try {
                $rows = $this->ingestSeason($season, $medals);
                $ingested += count($rows);

                // Une saison sans table exploitable a deux cas :
                //  - archivée : la compétition est close sans classement final
                //    (playoffs de division, 3e place…) → jamais de tables, on
                //    la marque ingérée pour cesser de la retenter.
                //  - en cours : les tables n'existent pas encore → elle reste
                //    pending et sera retentée à la prochaine passe.
                if ($rows === []) {
                    if ((bool) ($season->archived ?? false)) {
                        $this->seasons->markTableIngested((int) $season->id);
                        Log::info('TableImporter : compétition archivée sans tables, marquée ingérée', [
                            'season_id' => (int) $season->id,
                            'competition' => $season->etf2l_competition_id,
                        ]);
                    } else {
                        Log::info('TableImporter : compétition en cours sans tables, laissée en attente', [
                            'season_id' => (int) $season->id,
                            'competition' => $season->etf2l_competition_id,
                        ]);
                    }

                    continue;
                }

                $this->seasons->markTableIngested((int) $season->id);

                if ($progress !== null) {
                    $progress($season, count($rows));
                }
            } catch (Throwable $e) {
                // Un 429 persistant ou une panne isolée ne doit pas arrêter le lot :
                // on passe à la saison suivante, la relance retentera celle-ci.
                $this->errors[] = "Saison {$season->etf2l_competition_id} ({$season->name}) : ".$e->getMessage();
                Log::warning('TableImporter : saison ignorée pour la prochaine passe', [
                    'season_id' => (int) $season->id,
                    'competition' => $season->etf2l_competition_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $ingested;
    }

    /**
     * Erreurs de la dernière exécution (affichage dans les commandes).
     *
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Récupère et structure les tables d'une saison (# lignes issues de l'API).
     *
     * @param  array<int, string>  $medals  placement => nom de médaille
     * @return list<array{season_id: int, team_id: int, division_name: string, ach: int|null, medal: string|null}>
     */
    private function ingestSeason(object $season, array $medals): array
    {
        $tables = $this->client->competitionTables((int) $season->etf2l_competition_id);
        $rows = [];

        foreach ($tables as $divisionName => $entries) {
            foreach ($entries as $entry) {
                $etf2lTeamId = (int) ($entry['id'] ?? 0);
                if ($etf2lTeamId <= 0) {
                    continue;
                }

                $teamId = $this->teams->insertOrIgnore([
                    'etf2l_id' => $etf2lTeamId,
                    'name' => (string) ($entry['name'] ?? ''),
                    'country' => (string) ($entry['country'] ?? ''),
                ]);

                $ach = $entry['ach'] ?? null;
                $placement = $this->normalizeAch($ach);
                $medal = $placement !== null ? (string) ($medals[$placement] ?? '') : '';

                $rows[] = [
                    'season_id' => (int) $season->id,
                    'team_id' => $teamId,
                    'division_name' => (string) ($entry['division_name'] ?? $divisionName),
                    'ach' => $placement,
                    'medal' => $medal !== '' ? $medal : null,
                ];
            }
        }

        if ($rows !== []) {
            DB::table('season_teams')->upsert(
                $rows,
                ['season_id', 'team_id'],
                ['division_name', 'ach', 'medal'],
            );
        }

        return $rows;
    }

    /**
     * Le champ "ach" de l'API peut être un entier (1|2|3) ; tout le reste est ignoré.
     */
    private function normalizeAch(mixed $ach): ?int
    {
        if (is_int($ach)) {
            return $ach >= 1 && $ach <= 3 ? $ach : null;
        }

        if (is_string($ach) && ctype_digit($ach)) {
            return $this->normalizeAch((int) $ach);
        }

        return null;
    }
}
