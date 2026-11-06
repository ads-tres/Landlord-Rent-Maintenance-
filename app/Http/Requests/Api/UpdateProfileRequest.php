<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'locale' => ['sometimes', 'in:am,en'],
            'calendar_pref' => ['sometimes', 'in:ethiopian,gregorian'],
            'sms_reminders' => ['sometimes', 'boolean'],
            // Online rent can only be switched on with an active payout account.
            'online_rent_enabled' => ['sometimes', 'boolean', function ($attribute, $value, $fail) {
                if ($value && ! $this->user()->payoutAccount?->isActive()) {
                    $fail(__('chapa.need_payout_account'));
                }
            }],
        ];
    }
}
