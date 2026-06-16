<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTimersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'timers'   => ['required', 'array', 'min:1'],
            // Note : la validation des plages min/max et de la configurabilité
            // est déléguée à GameService::validateTimerSettings() pour centraliser
            // la logique métier des timers.
            'timers.*' => ['required', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'timers.required'  => 'Les timers sont obligatoires.',
            'timers.array'     => 'Format de timers invalide.',
            'timers.min'       => 'Au moins un timer doit être fourni.',
            'timers.*.required' => 'La valeur du timer est obligatoire.',
            'timers.*.integer' => 'Chaque timer doit être un nombre entier de secondes.',
        ];
    }
}
