<?php

use App\Models\GamePlayer;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Tous les joueurs de la partie
Broadcast::channel('game.{gameId}', function ($user, $gameId) {
    return GamePlayer::where('game_id', $gameId)
        ->where('user_id', $user->id)
        ->exists();
});

// Canal privé d'un joueur individuel
Broadcast::channel('game.{gameId}.player.{playerId}', function ($user, $gameId, $playerId) {
    return GamePlayer::where('id', $playerId)
        ->where('game_id', $gameId)
        ->where('user_id', $user->id)
        ->exists();
});

// Canal privé des loups-garous
Broadcast::channel('game.{gameId}.werewolves', function ($user, $gameId) {
    $player = GamePlayer::where('game_id', $gameId)
        ->where('user_id', $user->id)
        ->first();

    return $player && $player->isWerewolf();
});

// Canal presence — détection déconnexion via leaving()
Broadcast::channel('game.{gameId}.presence', function ($user, $gameId) {
    $player = GamePlayer::where('game_id', $gameId)
        ->where('user_id', $user->id)
        ->first();

    if (! $player) {
        return false;
    }

    return ['id' => $player->id, 'pseudo' => $player->pseudo];
});
