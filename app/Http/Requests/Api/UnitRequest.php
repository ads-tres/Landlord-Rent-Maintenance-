<?php

namespace App\Http\Requests\Api;

use App\Models\Unit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UnitRequest extends FormRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';
        $property = $this->route('property');
        $unit = $this->route('unit');

        return [
            'label' => [
                $required, 'string', 'max:50',
                Rule::unique('units', 'label')
                    ->where('property_id', $property?->id ?? $unit?->property_id)
                    ->ignore($unit?->id),
            ],
            'bedrooms' => ['nullable', 'integer', 'min:0', 'max:20'],
            'status' => ['sometimes', Rule::in([Unit::STATUS_VACANT, Unit::STATUS_OCCUPIED])],
        ];
    }
}
