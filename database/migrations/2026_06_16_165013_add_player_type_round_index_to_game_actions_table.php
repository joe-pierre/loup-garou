<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_actions', function (Blueprint $table) {
            $table->index(
                ['game_id', 'player_id', 'type', 'round'],
                'idx_game_actions_player_type_round'
            );
        });
    }

    public function down(): void
    {
        Schema::table('game_actions', function (Blueprint $table) {
            $table->dropIndex('idx_game_actions_player_type_round');
        });
    }
};
