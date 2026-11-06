<?php

namespace App\Http\Requests\Api;

use App\Models\MaintenanceRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMaintenanceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'unit_id' => ['required', 'integer', Rule::exists('units', 'id')->where('landlord_id', $this->user()->landlordId())],
            'title' => ['required', 'string', 'max:120'],
            'category' => ['sometimes', Rule::in(MaintenanceRequest::CATEGORIES)],
            'description' => ['nullable', 'string', 'max:2000'],
            'client_ref' => ['sometimes', 'nullable', 'string', 'max:64'],
            'photos' => ['sometimes', 'array', 'max:4'],
            'photos.*' => ['image', 'mimes:jpeg,jpg,png,webp,heic', 'max:8192'],
        ];
    }
}
