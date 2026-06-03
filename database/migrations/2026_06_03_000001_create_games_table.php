<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->string('code', 6)->unique();
            $table->enum('status', ['waiting', 'electing_mayor', 'night', 'day', 'finished'])->default('waiting');
            $table->unsignedInteger('max_players');
            $table->unsignedInteger('round')->default(0);
            $table->timestamp('phase_deadline')->nullable();
            $table->enum('winner_team', ['villagers', 'werewolves'])->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
