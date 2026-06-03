<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class JoinGameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pseudo' => ['required', 'string', 'min:2', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'pseudo.required' => 'Le pseudo est obligatoire.',
            'pseudo.min'      => 'Le pseudo doit faire au moins 2 caractères.',
            'pseudo.max'      => 'Le pseudo ne peut pas dépasser 20 caractères.',
        ];
    }
}
