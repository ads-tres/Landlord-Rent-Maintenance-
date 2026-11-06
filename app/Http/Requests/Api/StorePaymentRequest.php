<?php

namespace App\Http\Requests\Api;

use App\Models\Payment;
use App\Support\DateDisplay;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Amounts are integers in santim. */
class StorePaymentRequest extends FormRequest
{
    private Payment|false|null $existing = null;

    /**
     * A payment already saved with this client_ref (an offline payment sent again).
     * The controller returns it instead of creating a duplicate.
     */
    public function existingPayment(): ?Payment
    {
        if ($this->existing === null) {
            $ref = $this->input('client_ref');
            $this->existing = is_string($ref) && $ref !== ''
                ? (Payment::query()->where('client_ref', $ref)->first() ?? false)
                : false;
        }

        return $this->existing ?: null;
    }

    public function rules(): array
    {
        if ($this->existingPayment()) {
            return ['client_ref' => ['required', 'string']];
        }

        $landlordId = $this->user()->landlordId();

        return [
            'lease_id' => ['required', 'integer', Rule::exists('leases', 'id')->where('landlord_id', $landlordId)],
            'amount' => ['required', 'integer', 'min:100', 'max:1000000000'],
            'paid_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'method' => ['required', Rule::in(Payment::MANUAL_METHODS)],
            // Telebirr and bank payments carry a transaction reference; the same one can't be logged
            // twice for the same channel (ignoring case, as references are often typed from an SMS).
            'reference' => [
                Rule::requiredIf(fn () => $this->input('method') !== 'cash'),
                'nullable', 'string', 'max:100',
                function (string $attribute, mixed $value, \Closure $fail) use ($landlordId) {
                    if (! is_string($value) || trim($value) === '') {
                        return;
                    }
                    $existing = Payment::query()
                        ->where('landlord_id', $landlordId)
                        ->where('method', $this->input('method'))
                        ->whereRaw('UPPER(reference) = ?', [mb_strtoupper(trim($value))])
                        ->with('tenant')
                        ->first();
                    if ($existing) {
                        $landlord = $this->user()->actingLandlord();
                        $locale = app()->getLocale();
                        $fail(__('payments.duplicate_reference', [
                            'date' => DateDisplay::date($existing->paid_at, $locale, $landlord->calendar_pref ?: DateDisplay::GREGORIAN),
                            'tenant' => $existing->tenant?->full_name ?? '—',
                        ]));
                    }
                },
            ],
            'notes' => ['nullable', 'string', 'max:500'],
            'send_sms' => ['sometimes', 'boolean'],
            'client_ref' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }
}
