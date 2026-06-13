<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'roles'   => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'integer', 'in:0,1'],
        ];
    }

    public function messages(): array
    {
        return [
            'roles.required'  => 'La composition des rôles est obligatoire.',
            'roles.array'     => 'Format de composition invalide.',
            'roles.min'       => 'Au moins un rôle doit être fourni.',
            'roles.*.required' => 'La valeur du rôle est obligatoire.',
            'roles.*.integer' => 'Chaque rôle doit valoir 0 ou 1.',
            'roles.*.in'      => 'Chaque rôle doit valoir 0 ou 1.',
        ];
    }
}
