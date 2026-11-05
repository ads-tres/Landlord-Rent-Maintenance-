<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\Concerns\NormalizesPhone;
use App\Rules\EthiopianPhone;
use Illuminate\Foundation\Http\FormRequest;

class SendOtpRequest extends FormRequest
{
    use NormalizesPhone;

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', new EthiopianPhone],
            'locale' => ['sometimes', 'in:am,en'],
        ];
    }
}
