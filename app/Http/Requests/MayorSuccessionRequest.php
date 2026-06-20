<?php

namespace App\Http\Requests;

use App\Models\GamePlayer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MayorSuccessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $gameId = $this->route('id');
        $player = GamePlayer::where('game_id', $gameId)
            ->where('user_id', $this->user()->id)
            ->first();

        return $player && $player->is_mayor && ! $player->is_alive;
    }

    public function rules(): array
    {
        return [
            'target_player_id' => [
                'required',
                'integer',
                Rule::exists('game_players', 'id')
                    ->where('game_id', $this->route('id'))
                    ->where('is_alive', true),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'target_player_id.required' => 'Le successeur est obligatoire.',
            'target_player_id.exists'   => 'Ce joueur n\'existe pas ou est éliminé.',
        ];
    }
}
