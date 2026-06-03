<?php

namespace App\Services;

use App\Events\Game\PlayerExcluded;
use App\Events\Game\PlayerJoined;
use App\Models\Exclusion;
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

    public function joinGame(User $user, string $code, string $pseudo): GamePlayer
    {
        return DB::transaction(function () use ($user, $code, $pseudo) {
            $game = Game::where('code', $code)->lockForUpdate()->first();

            if (! $game) {
                abort(404, 'Partie introuvable.');
            }

            if ($game->status !== 'waiting') {
                abort(409, 'Cette partie a déjà commencé.');
            }

            // Vérifier si l'utilisateur est déjà dans la partie
            $existing = GamePlayer::where('game_id', $game->id)
                ->where('user_id', $user->id)
                ->first();

            if ($existing) {
                return $existing;
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

            $player = GamePlayer::create([
                'game_id'   => $game->id,
                'user_id'   => $user->id,
                'pseudo'    => $pseudo,
                'joined_at' => now(),
            ]);

            broadcast(new PlayerJoined($game, $player));

            return $player;
        });
    }

    public function excludePlayer(GamePlayer $host, GamePlayer $target, string $reason): void
    {
        $game = $host->game;

        if ($game->status !== 'waiting') {
            abort(409, 'Impossible d\'exclure un joueur après le début de la partie.');
        }

        if (! $host->is_host) {
            abort(403, 'Seul le host peut exclure un joueur.');
        }

        if ($host->id === $target->id) {
            abort(403, 'Le host ne peut pas s\'exclure lui-même.');
        }

        DB::transaction(function () use ($game, $target, $reason) {
            // Insérer dans exclusions avant suppression (FK) — $target reste en mémoire après delete()
            Exclusion::create([
                'game_id'     => $game->id,
                'player_id'   => $target->id,
                'reason'      => $reason,
                'excluded_at' => now(),
            ]);

            $target->delete();

            // Broadcast public (pseudo seulement) puis privé (avec motif)
            // L'event stocke des scalaires : pas de dépendance DB après delete
            broadcast(new PlayerExcluded($game, $target));
            broadcast(new PlayerExcluded($game, $target, $reason));
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
