<?php

namespace App\Jobs;

use App\Events\Game\SeerResult;
use App\Models\Game;
use App\Models\GameAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessSeerAutoAction implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $gameId,
        public readonly int $seerId,
        public readonly int $round,
    ) {}

    public function handle(): void
    {
        $game = Game::find($this->gameId);

        if (! $game || $game->status !== 'night' || $game->round !== $this->round) {
            return;
        }

        // Voyante a déjà agi → rien à faire
        $alreadyActed = GameAction::where('game_id', $this->gameId)
            ->where('player_id', $this->seerId)
            ->where('type', 'seer_check')
            ->where('round', $this->round)
            ->exists();

        if ($alreadyActed) {
            return;
        }

        $seer = $game->players()->where('id', $this->seerId)->first();
        if (! $seer || ! $seer->is_alive) {
            return;
        }

        // Choisir une cible aléatoire (pas la voyante elle-même)
        $target = $game->alivePlayers()
            ->where('id', '!=', $this->seerId)
            ->inRandomOrder()
            ->first();

        if (! $target) {
            return;
        }

        GameAction::create([
            'game_id'          => $this->gameId,
            'player_id'        => $this->seerId,
            'type'             => 'seer_check',
            'target_player_id' => $target->id,
            'round'            => $this->round,
            'phase'            => 'night',
        ]);

        // Broadcast le résultat sur le channel privé de la voyante
        // → côté client seer_result déclenche l'affichage
        // → après 5s ProcessWerewolvesTurn démarre (géré dans ProcessSeerTurn)
        broadcast(new SeerResult($game, $seer, $target));
    }
}