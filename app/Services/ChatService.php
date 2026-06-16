<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\GamePlayer;

/**
 * Gère l'envoi de messages dans les canaux de chat d'une partie.
 *
 * Canaux disponibles et règles d'accès :
 * - 'general'    : joueurs vivants, phases 'electing_mayor', 'day' et 'processing_day' uniquement
 * - 'werewolves' : loups uniquement (rôle 'werewolf'/'white_wolf'), phase 'night' uniquement
 * - 'dead'       : joueurs éliminés uniquement, phases 'day' et 'processing_day' uniquement
 *
 * Règle d'écriture : un joueur mort ne peut écrire que sur 'dead'.
 * Un joueur vivant ne peut jamais écrire sur 'dead'.
 * Les messages 'dead' sont diffusés sur le canal public game.{id} mais routés côté client
 * (pas de PrivateChannel dédié — cf. DECISIONS.md "Canal des fantômes").
 */
class ChatService
{
    /**
     * Valide les droits d'accès au canal et persiste le message en base.
     * La phase de jeu est traduite en valeur ENUM DB via mapStatusToPhase().
     *
     * @param  GamePlayer $player  Auteur du message
     * @param  string     $message Contenu (max 200 caractères, validé par SendMessageRequest)
     * @param  string     $channel Canal cible : 'general', 'werewolves' ou 'dead'
     * @return ChatMessage          Le message persisté
     * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException (403) si le joueur n'a pas le droit d'écrire sur ce canal
     * @throws \Symfony\Component\HttpKernel\Exception\ConflictHttpException     (409) si la phase ne permet pas l'envoi sur ce canal
     */
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

    /**
     * Traduit un statut de partie en valeur ENUM pour la colonne chat_messages.phase.
     * Les statuts intermédiaires ('processing_day', 'wolves_turn', etc.) sont tous mappés sur 'day'.
     *
     * @param  string $status Statut courant de la partie (ex. 'day', 'night', 'electing_mayor')
     * @return string          Valeur ENUM : 'election', 'night' ou 'day'
     */
    private function mapStatusToPhase(string $status): string
    {
        return match ($status) {
            'electing_mayor' => 'election',
            'night'          => 'night',
            default          => 'day',
        };
    }
}
