<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Facades\DB;

/**
 * Accès aux équipes (table teams).
 */
final class TeamsRepository
{
    /**
     * Insère l'équipe si elle n'existe pas, retourne son id interne.
     *
     * @param  array<string, mixed>  $row  clés : etf2l_id, name, country
     */
    public function insertOrIgnore(array $row): ?int
    {
        $etf2lId = (int) ($row['etf2l_id'] ?? 0);
        if ($etf2lId <= 0) {
            return null;
        }

        $team = $this->findByEtf2lId($etf2lId);
        if ($team !== null) {
            return (int) $team->id;
        }

        return (int) DB::table('teams')->insertGetId([
            'etf2l_team_id' => $etf2lId,
            'name' => (string) ($row['name'] ?? ''),
            'country' => (string) ($row['country'] ?? ''),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function findByEtf2lId(int $etf2lId): ?object
    {
        return DB::table('teams')->where('etf2l_team_id', $etf2lId)->first();
    }

    /**
     * Tous les ids ETF2L des équipes connues (pour la récolte des joueurs).
     *
     * @return array<int, int>
     */
    public function allEtf2lIds(): array
    {
        return DB::table('teams')
            ->orderBy('etf2l_team_id')
            ->pluck('etf2l_team_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
