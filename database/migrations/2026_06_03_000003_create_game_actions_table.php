<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained('game_players')->cascadeOnDelete();
            $table->enum('type', ['mayor_vote', 'night_vote', 'day_vote', 'seer_check', 'mayor_succession', 'werewolf_chat', 'ready']);
            $table->tinyInteger('weight')->default(1);
            $table->foreignId('target_player_id')->nullable()->constrained('game_players')->nullOnDelete();
            $table->unsignedInteger('round');
            $table->enum('phase', ['election', 'night', 'day']);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['game_id', 'round', 'phase']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_actions');
    }
};
