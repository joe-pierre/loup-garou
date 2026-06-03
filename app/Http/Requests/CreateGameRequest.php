<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateGameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pseudo'      => ['required', 'string', 'min:2', 'max:20'],
            'max_players' => ['required', 'integer', 'in:6,8,10,12'],
        ];
    }

    public function messages(): array
    {
        return [
            'pseudo.required'      => 'Le pseudo est obligatoire.',
            'pseudo.min'           => 'Le pseudo doit faire au moins 2 caractères.',
            'pseudo.max'           => 'Le pseudo ne peut pas dépasser 20 caractères.',
            'max_players.required' => 'Le nombre de joueurs est obligatoire.',
            'max_players.in'       => 'Le nombre de joueurs doit être 6, 8, 10 ou 12.',
        ];
    }
}
