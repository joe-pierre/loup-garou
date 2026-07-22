<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE game_players MODIFY COLUMN role ENUM(
            'villager',
            'werewolf',
            'seer',
            'witch',
            'hunter',
            'cupidon'
        ) NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE game_players MODIFY COLUMN role ENUM(
            'villager',
            'werewolf',
            'seer',
            'witch',
            'hunter'
        ) NULL");
    }
};
