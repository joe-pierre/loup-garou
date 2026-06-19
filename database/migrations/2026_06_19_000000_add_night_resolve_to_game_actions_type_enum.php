<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE game_actions MODIFY COLUMN type ENUM(
            'mayor_vote','night_vote','day_vote','seer_check','mayor_succession',
            'werewolf_chat','ready','witch_heal','witch_kill','witch_pass',
            'hunter_shot','hunter_pending','night_resolve'
        ) NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE game_actions MODIFY COLUMN type ENUM(
            'mayor_vote','night_vote','day_vote','seer_check','mayor_succession',
            'werewolf_chat','ready','witch_heal','witch_kill','witch_pass',
            'hunter_shot','hunter_pending'
        ) NOT NULL");
    }
};
