<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Accès aux joueurs (table players). L'identifiant canonique est l'id ETF2L.
 */
final class PlayersRepository
{
    /**
     * Insère ou met à jour un joueur depuis le payload /player/{id} de l'API.
     *
     * @param  array<string, mixed>  $player
     */
    public function upsertFromApi(array $player): void
    {
        $etf2lId = (int) ($player['id'] ?? 0);
        if ($etf2lId <= 0) {
            return;
        }

        $steam = $player['steam'] ?? null;
        $steamId64 = is_array($steam) ? (string) ($steam['id64'] ?? '') : '';
        $avatar = is_array($steam) ? (string) ($steam['avatar'] ?? '') : '';

        DB::table('players')->upsert(
            [[
                'etf2l_id' => $etf2lId,
                'name' => (string) ($player['name'] ?? ''),
                'country' => (string) ($player['country'] ?? ''),
                'steam_id64' => $steamId64 !== '' ? $steamId64 : null,
                'avatar' => $avatar !== '' ? $avatar : null,
                'updated_at' => now(),
            ]],
            ['etf2l_id'],
            ['name', 'country', 'steam_id64', 'avatar', 'updated_at'],
        );
    }

    /**
     * Insère une liste de joueurs candidats récoltés depuis les rosters et
     * transferts des équipes (ne modifie jamais une entrée existante).
     *
     * @param  array<int, array<string, mixed>>  $rows  clés : etf2l_id, name, country, steam_id64, avatar
     * @return int nombre de nouveaux joueurs insérés
     */
    public function insertCandidates(array $rows): int
    {
        $rows = array_values(array_filter($rows, static fn (array $r): bool => (int) ($r['etf2l_id'] ?? 0) > 0));
        if ($rows === []) {
            return 0;
        }

        return DB::table('players')->insertOrIgnore($rows);
    }

    public function findByEtf2lId(int $etf2lId): ?object
    {
        return DB::table('players')->where('etf2l_id', $etf2lId)->first();
    }

    public function findById(int $id): ?object
    {
        return DB::table('players')->where('id', $id)->first();
    }

    /**
     * Indique si le palmarès du joueur doit être recalculé.
     */
    public function isStale(object $player): bool
    {
        $now = time();
        $staleness = (int) config('palmares.staleness.palmares_compute_s');
        $computedAt = (int) ($player->computed_at ?? 0);

        return $computedAt <= 0 || ($computedAt + $staleness) < $now;
    }

    public function markComputed(int $id): void
    {
        DB::table('players')
            ->where('id', $id)
            ->update(['computed_at' => time(), 'updated_at' => now()]);
    }

    /**
     * Nombre de joueurs dont le palmarès n'a jamais été calculé.
     *
     * Contrairement à pending(), on ne considère que computed_at NULL : les
     * joueurs dont la staleness est dépassée sont un rafraîchissement
     * différé, pas du travail de backfill.
     */
    public function countUncomputed(): int
    {
        return (int) DB::table('players')->whereNull('computed_at')->count();
    }

    /**
     * Joueurs dont le palmarès reste à calculer ou est obsolète
     * (traités en lot par app:compute-palmares).
     *
     * @return array<int, object>
     */
    public function pending(int $limit = 50): array
    {
        $staleness = (int) config('palmares.staleness.palmares_compute_s');

        return DB::table('players')
            // Jamais calculé (computed_at NULL) ou obsolète (staleness dépassée).
            ->where(fn ($query): Builder => $query
                ->whereNull('computed_at')
                ->orWhere('computed_at', '<=', time() - $staleness))
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * Entrées compactes pour l'index de recherche.
     *
     * @return array<int, array<string, mixed>>
     */
    public function allForIndex(int $limit = 50000): array
    {
        return DB::table('players')
            ->select(['etf2l_id', 'name', 'country'])
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(static fn (object $p): array => [
                'etf2l_id' => (int) $p->etf2l_id,
                'name' => (string) $p->name,
                'country' => $p->country !== null ? (string) $p->country : '',
            ])
            ->all();
    }
}
