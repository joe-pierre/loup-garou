<?php

namespace App\Http\Controllers\Game;

use App\Events\Game\ChatMessageSent;
use App\Events\Game\WerewolfChatMessage;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendMessageRequest;
use App\Models\GamePlayer;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;

class ChatController extends Controller
{
    public function __construct(private ChatService $chatService) {}

    public function send(SendMessageRequest $request, int $id): JsonResponse
    {
        $player = GamePlayer::where('game_id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $chatMessage = $this->chatService->sendMessage(
            $player,
            $request->validated('message'),
            $request->validated('channel'),
        );

        $timestamp = now()->toISOString();

        if ($chatMessage->channel === 'werewolves') {
            broadcast(new WerewolfChatMessage($player->game, $player, $chatMessage->message, $timestamp));
        } else {
            broadcast(new ChatMessageSent($player->game, $player, $chatMessage->message, $timestamp));
        }

        return response()->json(['success' => true, 'data' => []]);
    }
}
