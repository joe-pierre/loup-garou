<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE games MODIFY COLUMN status ENUM(
            'waiting',
            'role_reveal',
            'electing_mayor',
            'night',
            'processing_night',
            'processing_wolves',
            'wolves_turn',
            'day',
            'finished'
        ) NOT NULL DEFAULT 'waiting'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE games MODIFY COLUMN status ENUM(
            'waiting',
            'role_reveal',
            'electing_mayor',
            'night',
            'processing_night',
            'processing_wolves',
            'day',
            'finished'
        ) NOT NULL DEFAULT 'waiting'");
    }
};
