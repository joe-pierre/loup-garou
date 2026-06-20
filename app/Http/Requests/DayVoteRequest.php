<?php

namespace App\Http\Requests;

use App\Models\GamePlayer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DayVoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $gameId        = $this->route('id');
        $currentPlayer = GamePlayer::where('game_id', $gameId)
            ->where('user_id', $this->user()->id)
            ->first();

        $selfId = $currentPlayer ? [$currentPlayer->id] : [];

        return [
            'target_player_id' => [
                'required',
                'integer',
                Rule::exists('game_players', 'id')
                    ->where('game_id', $gameId)
                    ->where('is_alive', true),
                Rule::notIn($selfId),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'target_player_id.required' => 'La cible est obligatoire.',
            'target_player_id.exists'   => 'Ce joueur n\'existe pas ou est déjà éliminé.',
            'target_player_id.not_in'   => 'Vous ne pouvez pas voter contre vous-même.',
        ];
    }
}
