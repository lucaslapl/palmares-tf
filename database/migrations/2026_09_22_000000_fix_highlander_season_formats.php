<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Corrige les formats historiques erronés : certaines compétitions
     * Highlander ont un type "6v6" dans l'API (ex. Highlander Season 32),
     * ce qui les a classées à tort en 6v6, dans seasons et palmares.
     */
    public function up(): void
    {
        $hlSeasons = DB::table('seasons')
            ->where('category', 'Highlander Season')
            ->where('format', '6s')
            ->pluck('id');

        if ($hlSeasons->isEmpty()) {
            return;
        }

        DB::table('seasons')->whereIn('id', $hlSeasons)->update(['format' => '9v9']);
        DB::table('palmares')->whereIn('season_id', $hlSeasons)->update(['format' => '9v9']);
    }

    public function down(): void
    {
        // Correction de données sans régression sûre : on ne restaure rien.
    }
};
