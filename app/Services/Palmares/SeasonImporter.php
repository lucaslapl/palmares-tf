<?php

declare(strict_types=1);

namespace App\Services\Palmares;

use App\Models\SeasonsRepository;
use App\Services\Etf2l\Etf2lApiClient;

/**
 * Importe la liste des compétitions de saison (6v6 / Highlander) depuis
 * /competition/list (app:sync-seasons).
 */
final class SeasonImporter
{
    public function __construct(
        private readonly Etf2lApiClient $client,
        private readonly SeasonsRepository $seasons,
    ) {}

    /**
     * @param  callable(array<string, mixed>): void|null  $progress
     * @return int nombre de saisons ingérées (nouvelles + déjà connues)
     */
    public function run(?callable $progress = null, int $limit = 0): int
    {
        $categories = (array) config('palmares.categories.seasons');
        $imported = 0;
        $page = 1;

        do {
            $payload = $this->client->competitionListPage($page);
            $container = $payload['competitions'] ?? [];
            $items = is_array($container) ? ($container['data'] ?? []) : [];

            foreach ($items as $competition) {
                $category = (string) ($competition['category'] ?? '');

                if (! in_array($category, $categories, true)) {
                    continue;
                }

                $this->seasons->insertOrIgnoreCompetition($competition);
                $imported++;

                if ($progress !== null) {
                    $progress($competition);
                }

                if ($limit > 0 && $imported >= $limit) {
                    break 2;
                }
            }

            $lastPage = $this->client->lastPage($payload, 'competitions');
            if ($page >= $lastPage) {
                break;
            }
            $page++;
        } while (true);

        return $imported;
    }
}
