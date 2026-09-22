<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Instantané de l'état du pipeline de données ETF2L (app:status).
 *
 * À lancer manuellement pour vérifier en deux secondes que le harvest avance :
 * volumes en base, travail restant, fraîcheur du cache API et des JSON publics,
 * dernière activité du scheduler (storage/logs/schedule.log).
 */
final class PipelineStatusCommand extends Command
{
    protected $signature = 'app:status';

    protected $description = 'Affiche l\'état du pipeline de données ETF2L (saisons, joueurs, cache, JSON)';

    public function handle(): int
    {
        $this->info('État du pipeline API ETF2L');

        $pendings = [];
        $seasons = (int) DB::table('seasons')->count();
        $pendingSeasons = (int) DB::table('seasons')->whereNull('ingested_at')->count();
        $teams = (int) DB::table('teams')->count();
        $players = (int) DB::table('players')->count();
        $polished = (int) DB::table('players')->whereNotNull('computed_at')->count();
        $pendingPlayers = (int) DB::table('players')->whereNull('computed_at')->count();
        $palmares = (int) DB::table('palmares')->count();

        if ($pendingSeasons > 0) {
            $pendings[] = "{$pendingSeasons} saison(s) sans tables";
        }
        if ($pendingPlayers > 0) {
            $pendings[] = "{$pendingPlayers} joueur(s) en attente";
        }

        $cache = (int) DB::table('etf2l_api_cache')->count();
        $lastFetch = (int) DB::table('etf2l_api_cache')->max('fetched_at');
        $cacheAge = $lastFetch > 0 ? $this->humanAge((int) $lastFetch) : 'jamais';

        $rows = [
            ['Saisons', $seasons, $pendingSeasons > 0 ? "{$pendingSeasons} en attente de tables" : 'ok'],
            ['Équipes', $teams, ''],
            ['Joueurs', $players, $pendingPlayers > 0 ? "{$pendingPlayers} en attente / {$polished} calculés" : 'tous calculés'],
            ['Palmarès', $palmares, 'lignes en base'],
            ['Cache API', $cache, "dernier appel il y a {$cacheAge}"],
        ];

        $this->table(['Élément', 'Volume', 'Note'], $rows);

        $this->newLine();
        $this->line('<fg=cyan>JSON publics ('.palmares_data_path().')</>');
        $this->renderJsonFiles();

        $logFile = storage_path('logs/schedule.log');
        $this->newLine();
        $this->line('<fg=cyan>Log scheduler</>');
        if (is_file($logFile)) {
            $this->line('  '.$logFile.' — dernière écriture il y a '.$this->humanAge((int) filemtime($logFile)));
            $this->line('  Dernières lignes :');
            $this->line('  '.str_replace("\n", "\n  ", $this->tail($logFile, 5)));
        } else {
            $this->warn('  Aucun log planifié : vérifiez que le scheduler est déclenché '
                .'(docker compose up -d scheduler, ou cron « * * * * * php artisan schedule:run »).');
        }

        if ($pendings !== []) {
            $this->newLine();
            $this->warn('Travail restant : '.implode(', ', $pendings).'. Lancer ./bin/backfill.sh ou app:sync-all pour avancer.');
        } else {
            $this->newLine();
            $this->info('Pipeline à jour : rien en attente.');
        }

        return self::SUCCESS;
    }

    private function renderJsonFiles(): void
    {
        $files = ['leaderboard.json', 'leaderboard-6s.json', 'leaderboard-9v9.json', 'players-index.json'];
        $rows = [];

        foreach ($files as $file) {
            $path = palmares_data_path($file);

            if (is_file($path)) {
                $rows[] = [$file, $this->humanSize((int) filesize($path)), 'il y a '.$this->humanAge((int) filemtime($path))];
            } else {
                $rows[] = [$file, '—', 'absent (régénéré à la prochaine lecture)'];
            }
        }

        $this->table(['Fichier', 'Taille', 'Fraîcheur'], $rows);
    }

    private function humanAge(int $timestamp): string
    {
        $diff = max(0, time() - $timestamp);

        return match (true) {
            $diff < 60 => $diff.' s',
            $diff < 3600 => intdiv($diff, 60).' min',
            $diff < 86400 => intdiv($diff, 3600).' h',
            default => intdiv($diff, 86400).' j',
        };
    }

    private function humanSize(int $bytes): string
    {
        $units = ['o', 'Ko', 'Mo', 'Go'];

        for ($i = 0; $bytes >= 1024 && $i < count($units) - 1; $i++) {
            $bytes = intdiv($bytes, 1024);
        }

        return $bytes.' '.$units[$i];
    }

    private function tail(string $path, int $lines): string
    {
        $content = file_get_contents($path);

        if (! is_string($content) || $content === '') {
            return '(vide)';
        }

        $all = preg_split('/\r?\n/', rtrim($content)) ?: [];

        return implode("\n", array_slice($all, -$lines));
    }
}
