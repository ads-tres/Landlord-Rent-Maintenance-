<?php

namespace App\Http\Requests\Api;

use App\Models\MaintenanceRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMaintenanceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in(MaintenanceRequest::STATUSES)],
            'title' => ['sometimes', 'string', 'max:120'],
            'category' => ['sometimes', Rule::in(MaintenanceRequest::CATEGORIES)],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'notify_tenant' => ['sometimes', 'boolean'],
        ];
    }
}
