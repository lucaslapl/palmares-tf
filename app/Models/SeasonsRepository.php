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
     * Compétitions satellites jamais affichées sur le site : brackets de
     * playoffs, matches de 3e place, qualifications. Elles n'ont pas de table
     * de classement finale et ne sont utiles qu'au calcul des profils joueurs
     * (rounds de playoffs), pas à l'affichage des saisons.
     */
    private const PLAYOFF_PATTERNS = [
        '/playoffs?/i',
        '/3rd\s*place/i',
        '/qualif/i',
    ];

    /**
     * Une compétition au nom de « sous-compétition » (playoffs, 3e place,
     * qualifications) n'est pas une saison à exposer dans la liste.
     */
    public static function isPlayoffCompetition(string $name): bool
    {
        foreach (self::PLAYOFF_PATTERNS as $pattern) {
            if (preg_match($pattern, $name) === 1) {
                return true;
            }
        }

        return false;
    }

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
        // La catégorie est l'indicateur fiable : certaines compétitions
        // Highlander ont un type "6v6" erroné dans l'API (ex. HL Season 32).
        if ($category === 'Highlander Season' || stripos($name, 'Highlander') !== false) {
            return '9v9';
        }
        if ($category === '6v6 Season' || stripos($name, '6v6') !== false) {
            return '6s';
        }

        $modeMap = (array) config('palmares.mode_map');

        return isset($modeMap[$type]) ? (string) $modeMap[$type] : null;
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
     * Repasse toutes les saisons en attente de tables (ingested_at = NULL).
     * Utilisé pour réarmer un import qui aurait marqué des saisons traitées
     * à tort (compétitions en cours ou réponses API vides).
     *
     * @return int nombre de saisons réarmées
     */
    public function resetTableIngestion(): int
    {
        return DB::table('seasons')->update(['ingested_at' => null, 'updated_at' => now()]);
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
