<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pseudo');
            $table->enum('role', ['villager', 'werewolf', 'seer'])->nullable();
            $table->boolean('is_alive')->default(true);
            $table->boolean('is_host')->default(false);
            $table->boolean('is_mayor')->default(false);
            $table->boolean('is_inactive')->default(false);
            $table->boolean('is_ready')->default(false);
            $table->timestamp('joined_at')->useCurrent();

            $table->unique(['game_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_players');
    }
};
