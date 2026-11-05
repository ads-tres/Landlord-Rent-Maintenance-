<?php

namespace App\Http\Requests\Api;

use App\Services\Rent\RentRules;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Lease fields follow Addis Ababa directive 7/2024: both parties, the house, the rent
 * and the property condition. Amounts are integers in santim (1 birr = 100 santim).
 */
class StoreLeaseRequest extends FormRequest
{
    public function rules(): array
    {
        $landlordId = $this->user()->landlordId();

        return [
            'unit_id' => ['required', 'integer', Rule::exists('units', 'id')->where('landlord_id', $landlordId)],
            'tenant_id' => ['required', 'integer', Rule::exists('tenants', 'id')->where('landlord_id', $landlordId)],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after:start_date'],
            'monthly_rent' => ['required', 'integer', 'min:100', 'max:100000000'],
            'deposit' => ['sometimes', 'integer', 'min:0', 'max:1000000000'],
            'due_day' => ['sometimes', 'integer', 'between:1,30'],
            // Ethiopian-calendar due dates arrive with the calendar feature.
            'due_calendar' => ['sometimes', Rule::in(['gregorian'])],
            'condition_notes' => ['required', 'string', 'max:5000'],
            'registered_at' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->hasAny(['start_date', 'end_date'])) {
                    return;
                }

                $start = CarbonImmutable::parse($this->input('start_date'));
                $minEnd = $start->addYears(RentRules::MIN_LEASE_YEARS)->subDay();

                if (CarbonImmutable::parse($this->input('end_date'))->lessThan($minEnd)) {
                    $validator->errors()->add('end_date', __('leases.min_term', [
                        'years' => RentRules::MIN_LEASE_YEARS,
                        'date' => $minEnd->toDateString(),
                    ]));
                }
            },
        ];
    }
}
