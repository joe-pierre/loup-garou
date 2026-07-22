<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE games MODIFY COLUMN winner_team ENUM(
            'villagers',
            'werewolves',
            'lovers'
        ) NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE games MODIFY COLUMN winner_team ENUM(
            'villagers',
            'werewolves'
        ) NULL");
    }
};
