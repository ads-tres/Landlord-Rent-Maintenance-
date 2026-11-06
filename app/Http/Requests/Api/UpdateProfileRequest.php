<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function rules(): array
    {
        // Rent reminders and online rent are the landlord's settings.
        if ($this->user()->isCaretaker()) {
            return [
                'name' => ['sometimes', 'nullable', 'string', 'max:255'],
                'locale' => ['sometimes', 'in:am,en'],
                'calendar_pref' => ['sometimes', 'in:ethiopian,gregorian'],
                'push_enabled' => ['sometimes', 'boolean'],
            ];
        }

        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'locale' => ['sometimes', 'in:am,en'],
            'calendar_pref' => ['sometimes', 'in:ethiopian,gregorian'],
            'sms_reminders' => ['sometimes', 'boolean'],
            'push_enabled' => ['sometimes', 'boolean'],
            // Printed on lease agreements and letters.
            'id_number' => ['sometimes', 'nullable', 'string', 'max:40'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Online rent can only be switched on with an active payout account.
            'online_rent_enabled' => ['sometimes', 'boolean', function ($attribute, $value, $fail) {
                if ($value && ! $this->user()->payoutAccount?->isActive()) {
                    $fail(__('chapa.need_payout_account'));
                }
            }],
        ];
    }
}
