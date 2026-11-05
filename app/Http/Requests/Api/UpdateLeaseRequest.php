<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/** Only details that don't change the money can be edited; rent goes through rent-change. */
class UpdateLeaseRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'condition_notes' => ['sometimes', 'string', 'max:5000'],
            'registered_at' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }
}
