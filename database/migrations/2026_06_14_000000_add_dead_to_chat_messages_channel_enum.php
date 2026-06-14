<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE chat_messages MODIFY COLUMN channel ENUM(
            'general',
            'werewolves',
            'dead'
        ) NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE chat_messages MODIFY COLUMN channel ENUM(
            'general',
            'werewolves'
        ) NOT NULL");
    }
};
