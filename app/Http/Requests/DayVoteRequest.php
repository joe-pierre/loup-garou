<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DayVoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'target_player_id' => 'required|integer',
        ];
    }
}
