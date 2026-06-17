<?php

use App\Models\GamePlayer;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Canal public : tout joueur de la partie peut s'abonner (vérifie game_id + user_id)
Broadcast::channel('game.{gameId}', function ($user, $gameId) {
    return GamePlayer::where('game_id', $gameId)
        ->where('user_id', $user->id)
        ->exists();
});

// Canal privé individuel : uniquement le joueur dont l'id correspond (game_id + player_id + user_id)
Broadcast::channel('game.{gameId}.player.{playerId}', function ($user, $gameId, $playerId) {
    return GamePlayer::where('id', $playerId)
        ->where('game_id', $gameId)
        ->where('user_id', $user->id)
        ->exists();
});

// Canal privé loups : uniquement les joueurs dont isWerewolf() === true (role IN werewolf, white_wolf)
Broadcast::channel('game.{gameId}.werewolves', function ($user, $gameId) {
    $player = GamePlayer::where('game_id', $gameId)
        ->where('user_id', $user->id)
        ->first();

    return $player && $player->isWerewolf();
});

// Canal presence : tous les joueurs (nécessaire pour la détection de déconnexion via leaving())
Broadcast::channel('game.{gameId}.presence', function ($user, $gameId) {
    $player = GamePlayer::where('game_id', $gameId)
        ->where('user_id', $user->id)
        ->first();

    if (! $player) {
        return false;
    }

    return ['id' => $player->id, 'pseudo' => $player->pseudo];
});
