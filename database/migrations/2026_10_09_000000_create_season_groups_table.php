<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Crée une table pour regrouper les compétitions (divisions) en saisons unifiées.
     * Par exemple, "6v6 Season 52 Division 1", "6v6 Season 52 Division 2" sont
     * regroupées sous "6v6 Season 52" (season_groups.id = X).
     */
    public function up(): void
    {
        // Table des groupes de saisons (saisons logiques unifiées).
        Schema::create('season_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('format', 8)->index(); // 6s ou 9v9
            $table->unsignedInteger('season_number'); // Numéro de saison (ex: 52)
            $table->string('name'); // Nom d'affichage (ex: "6v6 Season 52")
            $table->unsignedInteger('min_competition_id')->nullable(); // Plus petit ID ETF2L du groupe
            $table->unsignedInteger('max_competition_id')->nullable(); // Plus grand ID ETF2L du groupe
            $table->timestamps();

            // Unicité : une saison ne peut exister qu'une fois par format.
            $table->unique(['format', 'season_number']);
        });

        // Ajouter la clé étrangère à la table seasons.
        Schema::table('seasons', function (Blueprint $table): void {
            $table->foreignId('season_group_id')->nullable()->after('format')->constrained('season_groups')->nullOnDelete();
            $table->index(['season_group_id']);
        });

        // Ajouter la clé étrangère à la table palmares pour référence future.
        Schema::table('palmares', function (Blueprint $table): void {
            $table->foreignId('season_group_id')->nullable()->after('season_id')->constrained('season_groups')->nullOnDelete();
            $table->index(['season_group_id']);
        });
    }

    public function down(): void
    {
        Schema::table('palmares', function (Blueprint $table): void {
            $table->dropForeign(['season_group_id']);
            $table->dropColumn('season_group_id');
        });

        Schema::table('seasons', function (Blueprint $table): void {
            $table->dropForeign(['season_group_id']);
            $table->dropColumn('season_group_id');
        });

        Schema::dropIfExists('season_groups');
    }
};
