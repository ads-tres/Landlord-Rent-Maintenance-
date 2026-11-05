<?php

namespace App\Http\Requests\Concerns;

use App\Support\PhoneNumber;

trait NormalizesPhone
{
    protected function prepareForValidation(): void
    {
        $phone = $this->input('phone');

        if (is_string($phone) && ($normalized = PhoneNumber::normalize($phone)) !== null) {
            $this->merge(['phone' => $normalized]);
        }
    }
}
