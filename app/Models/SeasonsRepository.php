<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Facades\DB;

/**
 * Accès aux saisons (compétitions de ligue ingérées).
 */
final class SeasonsRepository
{
    /**
     * Ajoute une compétition si elle n'existe pas déjà (par id ETF2L).
     *
     * @param  array<string, mixed>  $competition  item de /competition/list
     */
    public function insertOrIgnoreCompetition(array $competition): void
    {
        $etf2lId = (int) ($competition['id'] ?? 0);
        $format = $this->resolveFormat((string) ($competition['type'] ?? ''), (string) ($competition['category'] ?? ''), (string) ($competition['name'] ?? ''));
        if ($etf2lId <= 0 || $format === null) {
            return;
        }

        DB::table('seasons')->insertOrIgnore([
            'etf2l_competition_id' => $etf2lId,
            'name' => (string) ($competition['name'] ?? ''),
            'category' => (string) ($competition['category'] ?? ''),
            'format' => $format,
            'archived' => (bool) ($competition['archived'] ?? false),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function resolveFormat(string $type, string $category, string $name): ?string
    {
        $modeMap = (array) config('palmares.mode_map');
        if (isset($modeMap[$type])) {
            return (string) $modeMap[$type];
        }

        // Repli sur le nom (certaines compétitions Highlander ont un type "6v6" erroné).
        if (stripos($name, 'Highlander') !== false || $category === 'Highlander Season') {
            return '9v9';
        }
        if (stripos($name, '6v6') !== false || $category === '6v6 Season') {
            return '6s';
        }

        return null;
    }

    public function findByCompetitionId(int $etf2lCompetitionId): ?object
    {
        return DB::table('seasons')->where('etf2l_competition_id', $etf2lCompetitionId)->first();
    }

    public function find(int $id): ?object
    {
        return DB::table('seasons')->where('id', $id)->first();
    }

    /**
     * Saisons sans tables de classement ingérées, ordonnées de la plus récente
     * à la plus ancienne (les compétitions récentes sont listées en premier par l'API).
     *
     * @return array<int, object>
     */
    public function pendingTables(): array
    {
        return DB::table('seasons')
            ->whereNull('ingested_at')
            ->orderByDesc('etf2l_competition_id')
            ->get()
            ->all();
    }

    public function markTableIngested(int $id): void
    {
        DB::table('seasons')
            ->where('id', $id)
            ->update(['ingested_at' => time(), 'updated_at' => now()]);
    }

    /**
     * Liste des saisons pour l'affichage.
     *
     * @return array<int, object>
     */
    public function listAll(?string $format = null): array
    {
        $query = DB::table('seasons');

        if ($format !== null) {
            $query->where('format', $format);
        }

        return $query->orderByDesc('etf2l_competition_id')->get()->all();
    }

    public function count(): int
    {
        return DB::table('seasons')->count();
    }
}
