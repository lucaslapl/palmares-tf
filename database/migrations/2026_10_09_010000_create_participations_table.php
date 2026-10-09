<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Participations aux compétitions (une ligne = un joueur × une
        // compétition où il a joué au moins un match). Contrairement au
        // palmares (podiums + playoffs uniquement), cette table couvre tous
        // les joueurs ayant réellement joué, pour les stats d'activité.
        Schema::create('participations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('season_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('competition_id')->nullable();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->string('format', 8)->nullable()->index();
            $table->string('competition_name')->nullable();
            $table->string('team_name')->nullable();
            $table->string('division_name')->nullable();
            $table->unsignedInteger('season_time')->nullable();
            $table->unique(['player_id', 'competition_id']);
            $table->index(['season_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('participations');
    }
};
