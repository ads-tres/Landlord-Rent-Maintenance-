<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\Concerns\NormalizesPhone;
use App\Rules\EthiopianPhone;
use Illuminate\Foundation\Http\FormRequest;

class VerifyOtpRequest extends FormRequest
{
    use NormalizesPhone;

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', new EthiopianPhone],
            'code' => ['required', 'string', 'digits:'.config('sms.otp.length')],
            'device_name' => ['sometimes', 'string', 'max:100'],
            'locale' => ['sometimes', 'in:am,en'],
        ];
    }
}
