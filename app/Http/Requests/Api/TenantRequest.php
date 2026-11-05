<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\Concerns\NormalizesPhone;
use App\Rules\EthiopianPhone;
use Illuminate\Foundation\Http\FormRequest;

class TenantRequest extends FormRequest
{
    use NormalizesPhone;

    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $required = $creating ? 'required' : 'sometimes';

        return [
            'full_name' => [$required, 'string', 'max:255'],
            'phone' => [$required, 'string', new EthiopianPhone],
            // Collect only what the lease needs (Proclamation 1321/2024).
            'id_number' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            // The landlord must confirm the tenant was shown the privacy notice.
            'privacy_notice_shown' => $creating ? ['required', 'accepted'] : ['prohibited'],
            'notice_locale' => ['sometimes', 'in:am,en'],
        ];
    }
}
