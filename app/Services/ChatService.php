<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\GamePlayer;

class ChatService
{
    public function sendMessage(GamePlayer $player, string $message, string $channel): ChatMessage
    {
        $game = $player->game;

        if (! $player->is_alive && $channel !== 'dead') {
            abort(403, 'Les joueurs éliminés ne peuvent pas envoyer de messages.');
        }

        if ($channel === 'werewolves') {
            if (! $player->isWerewolf()) {
                abort(403, 'Seuls les loups peuvent écrire dans le canal des loups.');
            }
            if ($game->status !== 'night') {
                abort(409, 'Le chat des loups n\'est disponible que pendant la phase nuit.');
            }
        }

        if ($channel === 'general') {
            if (! in_array($game->status, ['electing_mayor', 'day', 'processing_day'])) {
                abort(409, 'Le chat général n\'est disponible que pendant l\'élection du maire et le jour.');
            }
        }

        if ($channel === 'dead') {
            if ($player->is_alive) {
                abort(403, 'Seuls les joueurs éliminés peuvent écrire dans le canal des fantômes.');
            }
            if (! in_array($game->status, ['day', 'processing_day'])) {
                abort(409, 'Le chat des fantômes n\'est disponible que pendant la phase jour.');
            }

            return ChatMessage::create([
                'game_id'   => $game->id,
                'player_id' => $player->id,
                'message'   => $message,
                'channel'   => 'dead',
                'round'     => $game->round,
                'phase'     => 'day',
            ]);
        }

        return ChatMessage::create([
            'game_id'   => $game->id,
            'player_id' => $player->id,
            'message'   => $message,
            'channel'   => $channel,
            'round'     => $game->round,
            'phase'     => $this->mapStatusToPhase($game->status),
        ]);
    }

    private function mapStatusToPhase(string $status): string
    {
        return match ($status) {
            'electing_mayor' => 'election',
            'night'          => 'night',
            default          => 'day',
        };
    }
}
