<?php

namespace App\Jobs;

use App\Events\Game\PlayerInactive;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Services\GameService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class CheckReconnectionTimeout implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int    $playerId,
        public readonly string $disconnectToken,
    ) {}

    public function handle(GameService $gameService): void
    {
        $player = GamePlayer::find($this->playerId);

        if (! $player) {
            return;
        }

        // Si le token en cache ne correspond plus → /reconnect a été appelé
        $cachedToken = Cache::get("player_disconnected.{$this->playerId}");
        if ($cachedToken !== $this->disconnectToken) {
            return;
        }

        Cache::forget("player_disconnected.{$this->playerId}");

        $player->update(['is_inactive' => true]);

        broadcast(PlayerInactive::fromPlayer($player));

        $game = Game::find($player->game_id);

        if (! $game || ! in_array($game->status, ['night', 'day', 'electing_mayor'], true)) {
            return;
        }

        $aliveCount         = $game->alivePlayers()->count();
        $inactiveAliveCount = $game->alivePlayers()->where('is_inactive', true)->count();

        if ($aliveCount > 0 && $inactiveAliveCount > $aliveCount / 2) {
            $gameService->cancelGame($game);
        }
    }
}
