<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MayorVoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
            'target_player_id.required' => 'La cible est obligatoire.',
            'target_player_id.exists'   => 'Ce joueur n\'existe pas ou est éliminé.',
        ];
    }
}
