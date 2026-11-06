<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/** Amount in santim. Readings (sub-meter method) as readings[unit_id][previous|current]. */
class UtilityBillRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'period' => ['required', 'date_format:Y-m'],
            'amount' => ['required', 'integer', 'min:100', 'max:100000000'],
            'due_date' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:500'],
            'readings' => ['sometimes', 'array'],
            'readings.*.previous' => ['required', 'numeric', 'min:0'],
            'readings.*.current' => ['required', 'numeric', 'min:0'],
            'photo' => ['nullable', 'image', 'max:8192'],
            'notify_tenants' => ['sometimes', 'boolean'],
            'dry_run' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<int, array{previous: float, current: float}> */
    public function readings(): array
    {
        return collect($this->validated('readings', []))
            ->mapWithKeys(fn ($r, $unitId) => [(int) $unitId => ['previous' => (float) $r['previous'], 'current' => (float) $r['current']]])
            ->all();
    }
}
