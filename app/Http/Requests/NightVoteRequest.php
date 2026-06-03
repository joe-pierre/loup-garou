<?php

namespace App\Http\Requests;

use App\Models\GamePlayer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NightVoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $gameId  = $this->route('id');
        $wolfIds = GamePlayer::where('game_id', $gameId)
            ->whereIn('role', ['werewolf', 'white_wolf'])
            ->pluck('id')
            ->toArray();

        return [
            'target_player_id' => [
                'required',
                'integer',
                Rule::exists('game_players', 'id')
                    ->where('game_id', $gameId)
                    ->where('is_alive', true),
                Rule::notIn($wolfIds),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'target_player_id.required' => 'La cible est obligatoire.',
            'target_player_id.exists'   => 'Ce joueur n\'existe pas ou est éliminé.',
            'target_player_id.not_in'   => 'Les loups ne peuvent pas voter contre un autre loup.',
        ];
    }
}
