<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExcludePlayerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Le motif est obligatoire.',
            'reason.max'      => 'Le motif ne peut pas dépasser 500 caractères.',
        ];
    }
}
