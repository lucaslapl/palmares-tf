<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Joueurs TF2 compétitifs (clé = identifiant ETF2L).
        Schema::create('players', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('etf2l_id')->unique();
            $table->string('name')->nullable()->index();
            $table->string('country')->nullable();
            $table->string('steam_id64', 32)->nullable();
            $table->string('avatar')->nullable();
            $table->unsignedInteger('computed_at')->nullable();
            $table->timestamps();
        });

        // Saisons (compétitions de ligue 6v6 / Highlander).
        Schema::create('seasons', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('etf2l_competition_id')->unique();
            $table->string('name');
            $table->string('category');
            $table->string('format', 8)->index();
            $table->boolean('archived')->default(false);
            $table->unsignedInteger('ingested_at')->nullable()->index();
            $table->timestamps();
        });

        // Équipes.
        Schema::create('teams', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('etf2l_team_id')->unique();
            $table->string('name')->nullable();
            $table->string('country')->nullable();
            $table->timestamps();
        });

        // Tables de classement final par saison (podiums dérivés du champ "ach").
        Schema::create('season_teams', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->string('division_name');
            $table->unsignedTinyInteger('ach')->nullable();
            $table->string('medal', 8)->nullable();
            $table->unique(['season_id', 'team_id']);
            $table->index(['season_id', 'division_name']);
        });

        // Palmarès calculé des joueurs (une ligne = une compétition retenue).
        Schema::create('palmares', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('season_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('competition_id')->nullable();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->string('format', 8)->nullable()->index();
            $table->string('competition_name')->nullable();
            $table->string('team_name')->nullable();
            $table->string('division_name')->nullable();
            $table->unsignedTinyInteger('placement')->nullable();
            $table->string('medal', 8)->nullable();
            $table->string('playoff_round')->nullable();
            $table->boolean('won_playoff')->default(false);
            $table->unsignedInteger('season_time')->nullable();
            $table->index(['player_id']);
            $table->index(['season_id']);
            $table->index(['medal']);
        });

        // Cache des réponses de l'API ETF2L (URL => payload JSON + timestamp).
        Schema::create('etf2l_api_cache', function (Blueprint $table): void {
            $table->string('url')->primary();
            $table->longText('payload');
            $table->unsignedInteger('fetched_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('etf2l_api_cache');
        Schema::dropIfExists('palmares');
        Schema::dropIfExists('season_teams');
        Schema::dropIfExists('teams');
        Schema::dropIfExists('seasons');
        Schema::dropIfExists('players');
    }
};
