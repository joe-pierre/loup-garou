<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exclusions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->nullable()->constrained('game_players')->nullOnDelete();
            $table->text('reason');
            $table->timestamp('excluded_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exclusions');
    }
};
