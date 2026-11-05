<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class EndLeaseRequest extends FormRequest
{
    public function rules(): array
    {
        $lease = $this->route('lease');

        return [
            'ended_on' => [
                'required', 'date_format:Y-m-d',
                'after_or_equal:'.$lease->start_date->toDateString(),
                'before_or_equal:'.$lease->end_date->toDateString(),
            ],
        ];
    }
}
