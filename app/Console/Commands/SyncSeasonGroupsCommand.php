<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SeasonGroupRepository;
use App\Models\SeasonsRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Commande pour créer/récréer les groupes de saisons à partir des
 * compétitions existantes. Utile pour migrer les données existantes ou
 * reconstruire les groupes après un changement de logique.
 */
final class SyncSeasonGroupsCommand extends Command
{
    protected $signature = 'app:sync-season-groups
        {--force : Forcer la recréation de tous les groupes}
        {--limit=0 : Nombre maximum de compétitions à traiter (0 = toutes)}';

    protected $description = 'Crée les groupes de saisons à partir des compétitions existantes';

    public function handle(
        SeasonGroupRepository $seasonGroups,
        SeasonsRepository $seasons,
    ): int {
        $force = (bool) $this->option('force');
        $limit = (int) max(0, (int) $this->option('limit'));

        if ($force) {
            $this->info('Réinitialisation des groupes de saisons…');
            $seasonGroups->resetAll();
            Log::info('app:sync-season-groups : réinitialisation des groupes');
        }

        $this->info('Synchronisation des groupes de saisons en cours…');

        $allSeasons = $seasons->listAll();
        $processed = 0;
        $created = 0;
        $updated = 0;

        foreach ($allSeasons as $season) {
            if ($limit > 0 && $processed >= $limit) {
                break;
            }

            $name = (string) ($season->name ?? '');
            $format = (string) ($season->format ?? '');
            $etf2lId = (int) ($season->etf2l_competition_id ?? 0);

            // Vérifier si déjà dans un groupe
            if (! $force && $season->season_group_id !== null) {
                $processed++;

                continue;
            }

            // Extraire le numéro de saison
            $seasonNumber = SeasonsRepository::extractSeasonNumber($name);

            if ($seasonNumber === null) {
                // Compétition sans numéro de saison (Nations Cup, etc.)
                $processed++;

                continue;
            }

            // Créer ou trouver le groupe (les saisons nommées par année
            // gardent leur nom d'origine comme libellé).
            $displayName = SeasonsRepository::isYearSeason($seasonNumber)
                ? SeasonsRepository::yearSeasonDisplayName($name)
                : null;
            $groupId = $seasonGroups->findOrCreate($format, $seasonNumber, $displayName);
            $seasonGroups->updateCompetitionRange($groupId, $etf2lId);

            // Associer la saison au groupe
            DB::table('seasons')
                ->where('id', $season->id)
                ->update(['season_group_id' => $groupId]);

            $created++;
            $processed++;

            $this->line('  + '.$name.' → Groupe #'.$groupId);
        }

        $this->info("Terminé : {$processed} compétit(s) traité(s), {$created} groupe(s) créé(s).");
        Log::info('app:sync-season-groups terminée', [
            'processed' => $processed,
            'created' => $created,
            'force' => $force,
            'limit' => $limit,
        ]);

        return self::SUCCESS;
    }
}
