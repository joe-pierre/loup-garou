<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WitchActRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $gameId = $this->route('id');

        return [
            'action' => ['required', 'string', 'in:heal,kill,pass'],
            'target_player_id' => [
                'nullable',
                'required_if:action,heal,kill',
                'integer',
                Rule::exists('game_players', 'id')->where('game_id', $gameId),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'action.required'           => 'L\'action est obligatoire.',
            'action.in'                 => 'Action invalide.',
            'target_player_id.required_if' => 'La cible est obligatoire pour cette action.',
            'target_player_id.exists'   => 'Ce joueur n\'existe pas.',
        ];
    }
}
