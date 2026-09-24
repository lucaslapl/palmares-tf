<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ajoute la date de fin de ban ETF2L (timestamp Unix, null si aucun ban).
     * L'API /player/{id} expose les bans dans un tableau "bans" ; la date la
     * plus lointaine sert à repérer les bans encore actifs au rendu.
     */
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table): void {
            $table->unsignedBigInteger('ban_until')->nullable()->after('avatar');
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table): void {
            $table->dropColumn('ban_until');
        });
    }
};
