<?php

namespace App\Http\Requests\Api;

use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Amounts are integers in santim. */
class StorePaymentRequest extends FormRequest
{
    public function rules(): array
    {
        $landlordId = $this->user()->landlordId();

        return [
            'lease_id' => ['required', 'integer', Rule::exists('leases', 'id')->where('landlord_id', $landlordId)],
            'amount' => ['required', 'integer', 'min:100', 'max:1000000000'],
            'paid_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'method' => ['required', Rule::in(Payment::MANUAL_METHODS)],
            // Telebirr and bank payments carry a transaction reference; the same one can't be logged twice.
            'reference' => [
                Rule::requiredIf(fn () => $this->input('method') !== 'cash'),
                'nullable', 'string', 'max:100',
                Rule::unique('payments', 'reference')
                    ->where('landlord_id', $landlordId)
                    ->where('method', $this->input('method')),
            ],
            'notes' => ['nullable', 'string', 'max:500'],
            'send_sms' => ['sometimes', 'boolean'],
        ];
    }
}
