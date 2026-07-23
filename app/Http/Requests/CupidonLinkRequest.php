<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CupidonLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $gameId = $this->route('id');

        return [
            'target1_player_id' => [
                'required',
                'integer',
                Rule::exists('game_players', 'id')
                    ->where('game_id', $gameId)
                    ->where('is_alive', true),
            ],
            'target2_player_id' => [
                'required',
                'integer',
                'different:target1_player_id',
                Rule::exists('game_players', 'id')
                    ->where('game_id', $gameId)
                    ->where('is_alive', true),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'target1_player_id.required'  => 'Le premier amoureux est obligatoire.',
            'target1_player_id.exists'    => 'Ce joueur n\'existe pas ou est déjà éliminé.',
            'target2_player_id.required'  => 'Le second amoureux est obligatoire.',
            'target2_player_id.exists'    => 'Ce joueur n\'existe pas ou est déjà éliminé.',
            'target2_player_id.different' => 'Cupidon ne peut pas coupler un joueur avec lui-même en double.',
        ];
    }
}
