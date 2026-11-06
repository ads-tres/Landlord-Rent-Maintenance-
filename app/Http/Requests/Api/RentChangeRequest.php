<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class RentChangeRequest extends FormRequest
{
    public function rules(): array
    {
        $lease = $this->route('lease');

        return [
            'new_rent' => ['required', 'integer', 'min:100', 'max:100000000'],
            'effective_date' => [
                'required', 'date_format:Y-m-d',
                'after_or_equal:'.$lease->start_date->toDateString(),
                'before_or_equal:'.$lease->end_date->toDateString(),
            ],
            'tenant_agreed' => ['sometimes', 'boolean'],
            'notify_tenant' => ['sometimes', 'boolean'],
        ];
    }
}
