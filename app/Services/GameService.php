<?php

namespace App\Services;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GameService
{
    public function createGame(User $user, string $pseudo, int $maxPlayers): Game
    {
        $code = $this->generateUniqueCode();

        return DB::transaction(function () use ($user, $pseudo, $maxPlayers, $code) {
            $game = Game::create([
                'code'        => $code,
                'status'      => 'waiting',
                'max_players' => $maxPlayers,
            ]);

            GamePlayer::create([
                'game_id'   => $game->id,
                'user_id'   => $user->id,
                'pseudo'    => $pseudo,
                'is_host'   => true,
                'joined_at' => now(),
            ]);

            return $game;
        });
    }

    private function generateUniqueCode(): string
    {
        do {
            $code = strtoupper(Str::random(6));
        } while (Game::where('code', $code)->exists());

        return $code;
    }
}
