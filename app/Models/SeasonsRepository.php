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
     * Extrait le numéro de saison depuis un nom de compétition.
     * Ex: "6v6 Season 52 Division 1" -> 52
     *     "Highlander Season 32" -> 32
     *
     * @return int|null numéro de saison ou null si non trouvé
     */
    public static function extractSeasonNumber(string $name): ?int
    {
        if (preg_match('/Season\s+(\d+)/i', $name, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Génère un identifiant de saison unifiée à partir du format et du numéro.
     * Ex: format="6s", number=52 -> "6s-52"
     *
     * @param  string  $format  Format (6s ou 9v9)
     * @param  int  $number  Numéro de saison
     */
    public static function seasonGroupKey(string $format, int $number): string
    {
        return $format.'-'.$number;
    }

    /**
     * Extrait l'identifiant de saison unifiée depuis un nom de compétition.
     * Utilise le format de la compétition et son numéro de saison.
     *
     * @param  string  $name  Nom de la compétition
     * @param  string  $format  Format (6s ou 9v9)
     */
    public static function extractSeasonGroupKey(string $name, string $format): ?string
    {
        $number = self::extractSeasonNumber($name);
        if ($number === null) {
            return null;
        }

        return self::seasonGroupKey($format, $number);
    }

    /**
     * Génère le nom d'affichage d'une saison unifiée.
     * Ex: format="6s", number=52 -> "6v6 Season 52"
     *
     * @param  string  $format  Format (6s ou 9v9)
     * @param  int  $number  Numéro de saison
     */
    public static function seasonGroupName(string $format, int $number): string
    {
        $formatLabels = (array) config('palmares.formats');
        $label = $formatLabels[$format]['label'] ?? ($format === '6s' ? '6v6' : '9v9');

        return $label.' Season '.$number;
    }

    /**
     * Ajoute une compétition si elle n'existe pas déjà (par id ETF2L) et
     * l'associe à son groupe de saison unifié (une saison logique regroupe
     * toutes les divisions : « 6v6 Season 52 Division 1 », « ... Division 2 »).
     *
     * @param  array<string, mixed>  $competition  item de /competition/list
     * @return int|null ID du groupe de saison rattaché, ou null
     */
    public function insertOrIgnoreCompetition(array $competition, ?SeasonGroupRepository $seasonGroups = null): ?int
    {
        $etf2lId = (int) ($competition['id'] ?? 0);
        $name = (string) ($competition['name'] ?? '');
        $format = $this->resolveFormat((string) ($competition['type'] ?? ''), (string) ($competition['category'] ?? ''), $name);
        if ($etf2lId <= 0 || $format === null) {
            return null;
        }

        // Extraire le numéro de saison et rattacher le groupe de saison unifié.
        $seasonNumber = self::extractSeasonNumber($name);
        $groupId = null;

        if ($seasonNumber !== null && $seasonGroups !== null) {
            $groupId = $seasonGroups->findOrCreate($format, $seasonNumber);
            $seasonGroups->updateCompetitionRange($groupId, $etf2lId);
        }

        DB::table('seasons')->insertOrIgnore([
            'etf2l_competition_id' => $etf2lId,
            'name' => $name,
            'category' => (string) ($competition['category'] ?? ''),
            'format' => $format,
            'season_group_id' => $groupId,
            'archived' => (bool) ($competition['archived'] ?? false),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Compétition déjà connue sans groupe : on rattache rétroactivement.
        if ($groupId !== null) {
            DB::table('seasons')
                ->where('etf2l_competition_id', $etf2lId)
                ->whereNull('season_group_id')
                ->update(['season_group_id' => $groupId, 'updated_at' => now()]);
        }

        return $groupId;
    }

    /**
     * Résout le format à partir du type, catégorie et nom de compétition.
     */
    public function resolveFormat(string $type, string $category, string $name): ?string
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
     * Nombre de saisons archivées dont les tables doivent encore être ingérées.
     *
     * Seules les compétitions archivées comptent : une compétition en cours
     * (archived = false) peut rester sans tables indéfiniment et ne doit pas
     * bloquer la fin du backfill.
     */
    public function countPendingArchived(): int
    {
        return (int) DB::table('seasons')
            ->whereNull('ingested_at')
            ->where('archived', true)
            ->count();
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
