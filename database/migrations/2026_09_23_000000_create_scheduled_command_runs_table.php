<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historique des exécutions des commandes app:* (suivi non-invasif via les
 * events CommandStarting/CommandFinished et ScheduledTaskFailed). Alimente le
 * panel admin de monitoring ; purgé après la rétention configurée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_command_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('command')->index();
            $table->unsignedInteger('started_at')->index();
            $table->unsignedInteger('finished_at')->nullable();
            $table->integer('exit_code')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_command_runs');
    }
};
