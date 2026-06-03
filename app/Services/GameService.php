<?php

namespace App\Services;

use App\Models\Exclusion;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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

    public function joinGame(User $user, string $code, string $pseudo): GamePlayer
    {
        $game = Game::where('code', $code)->first();

        if (! $game) {
            abort(404, 'Partie introuvable.');
        }

        if ($game->status !== 'waiting') {
            abort(409, 'Cette partie a déjà commencé.');
        }

        if ($game->players()->count() >= $game->max_players) {
            abort(409, 'Cette partie est déjà complète.');
        }

        $excluded = Exclusion::where('game_id', $game->id)
            ->whereHas('player', fn ($q) => $q->where('user_id', $user->id))
            ->exists();

        if ($excluded) {
            abort(403, 'Tu as été exclu de cette partie.');
        }

        return GamePlayer::create([
            'game_id'   => $game->id,
            'user_id'   => $user->id,
            'pseudo'    => $pseudo,
            'joined_at' => now(),
        ]);
    }

    private function generateUniqueCode(): string
    {
        do {
            $code = strtoupper(Str::random(6));
        } while (Game::where('code', $code)->exists());

        return $code;
    }
}
