<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:200'],
            'channel' => ['required', 'string', Rule::in(['general', 'werewolves'])],
        ];
    }

    public function messages(): array
    {
        return [
            'message.required' => 'Le message est obligatoire.',
            'message.max'      => 'Le message ne peut pas dépasser 200 caractères.',
            'channel.required' => 'Le canal est obligatoire.',
            'channel.in'       => 'Canal invalide. Valeurs acceptées : general, werewolves.',
        ];
    }
}
