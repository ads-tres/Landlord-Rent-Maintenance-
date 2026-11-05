<?php

namespace App\Http\Requests\Api;

use App\Models\Meter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class MeterRequest extends FormRequest
{
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $propertyId = $this->route('property')?->id ?? $this->route('meter')?->property_id;

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:100'],
            'kind' => [$creating ? 'required' : 'sometimes', Rule::in(Meter::KINDS)],
            'split_method' => [$creating ? 'required' : 'sometimes', Rule::in(Meter::METHODS)],
            'units' => [$creating ? 'required' : 'sometimes', 'array', 'min:1'],
            'units.*.unit_id' => ['required', 'integer', 'distinct', Rule::exists('units', 'id')->where('property_id', $propertyId)],
            'units.*.share_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            $method = $this->input('split_method', $this->route('meter')?->split_method);
            if ($method !== 'percent' || ! is_array($this->input('units')) || $v->errors()->isNotEmpty()) {
                return;
            }
            $sum = collect($this->input('units'))->sum(fn ($u) => (float) ($u['share_percent'] ?? 0));
            if (abs($sum - 100) > 0.01) {
                $v->errors()->add('units', __('utilities.shares_must_total', ['total' => rtrim(rtrim(number_format($sum, 2), '0'), '.')]));
            }
        }];
    }
}
