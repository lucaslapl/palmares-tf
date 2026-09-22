<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PlayersRepository;
use App\Models\SeasonsRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Backfill complet du pipeline (app:backfill).
 *
 * Enchaîne en continu la chaîne saisons → tables → joueurs → palmarès → JSON,
 * en une seule commande relançable par le cron Plesk ou par bin/backfill.sh.
 * Une exécution trace :
 *  - un verrou de fichier (flock) : si le backfill tourne déjà ailleurs, on
 *    sort immédiatement (code 0) et la prochaine exécution fera le travail ;
 *  - un budget de temps (--runtime) : à l'échéance, on régénère les JSON si
 *    du travail a avancé et on sort avec le code 0 = « il reste du travail »,
 *    le déclencheur (cron / script) relance aussitôt une nouvelle tranche ;
 *  - un code 4 quand plus rien n'est à faire (backfill terminé), même
 *    sémantique que app:compute-palmares --exit-on-empty.
 *
 * Les échecs d'étape (429 persistant, panne API) sont attrapés et loggés :
 * le relancement suivant retente naturellement, sans jamais boucler à chaud.
 */
final class BackfillCommand extends Command
{
    /** Plus de travail : backfill terminé (même code que --exit-on-empty). */
    private const EXIT_DONE = 4;

    protected $signature = 'app:backfill
        {--runtime=1800 : Durée maximale d\'une tranche en secondes (bornée aux étapes qui le supportent)}';

    protected $description = 'Enchaîne tout le pipeline (saisons → tables → joueurs → palmarès → JSON) en continu';

    public function handle(SeasonsRepository $seasons, PlayersRepository $players): int
    {
        $runtime = max(60, (int) $this->option('runtime'));
        $lock = $this->acquireLock();

        if ($lock === false) {
            $this->info('Backfill déjà en cours (verrou détenu) : cette exécution ne fait rien.');

            return self::SUCCESS;
        }

        try {
            return $this->runTranche($seasons, $players, $runtime);
        } finally {
            $this->releaseLock($lock);
        }
    }

    /**
     * Verrou de fichier non bloquant : garantit qu'une seule tranche tourne à
     * la fois, quel que soit le nombre de déclencheurs (cron, script, main).
     */
    private function acquireLock()
    {
        $lock = @fopen(palmares_data_path('backfill.lock'), 'c');
        if ($lock === false) {
            $this->warn('Impossible d\'ouvrir le verrou du backfill : on continue sans protection.');

            return null;
        }

        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return false;
        }

        return $lock;
    }

    private function releaseLock($lock): void
    {
        if (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Exécute une tranche du pipeline et décide du code de sortie.
     */
    private function runTranche(SeasonsRepository $seasons, PlayersRepository $players, int $runtime): int
    {
        // Déjà à jour : on évite de re-récolter à chaque tick du cron.
        // Sur une base quasi vide on passe quand même : sans première
        // synchronisation, on ne saurait jamais qu'il n'y a rien à faire.
        if ($seasons->count() > 0 && $seasons->countPendingArchived() === 0 && $players->countUncomputed() === 0) {
            $this->info('Pipeline à jour : rien à backfiller.');

            return self::EXIT_DONE;
        }

        $start = time();
        $before = $this->workState($seasons, $players);
        $this->info("Tranche de backfill démarrée (runtime {$runtime}s).");

        try {
            $this->call('app:sync-seasons');
            $this->call('app:sync-tables');

            $this->call('app:harvest-players');

            // Les palmarès sont bornés par le temps restant de la tranche pour
            // ne jamais déborder du budget global.
            $remaining = max(60, $start + $runtime - time());
            $code = $this->call('app:compute-palmares', ['--runtime' => $remaining]);

            if ($code !== self::SUCCESS) {
                Log::warning('app:backfill : app:compute-palmares en échec partiel', ['exit' => $code]);
                $this->warn('Palmarès : certains joueurs en échec (retentés à la prochaine tranche).');
            }
        } catch (Throwable $e) {
            // 429 persistant ou panne de l'API : on laisse le déclencheur rela-
            // cer. Aucune boucle chaude : prochaine tranche immédiate ensuite.
            Log::warning('app:backfill : étape en échec, tranche interrompue', ['error' => $e->getMessage()]);
            $this->warn('Étape en échec ('.$e->getMessage().') : prochaine tranche retentera.');
        }

        $after = $this->workState($seasons, $players);
        $progressed = $after !== $before;

        if ($progressed) {
            $this->call('app:generate-json');
        }

        if ($after === [0, 0]) {
            $this->info('Backfill terminé : plus aucune saison archivée ni joueur à traiter.');

            return self::EXIT_DONE;
        }

        $this->line('Il reste du travail (saisons archivées en attente : '.$after[0].', joueurs à calculer : '.$after[1].').');

        return self::SUCCESS;
    }

    /**
     * État courant du travail restant pour mesurer la progression.
     *
     * @return array{0: int, 1: int}
     */
    private function workState(SeasonsRepository $seasons, PlayersRepository $players): array
    {
        return [
            $seasons->countPendingArchived(),
            $players->countUncomputed(),
        ];
    }
}
