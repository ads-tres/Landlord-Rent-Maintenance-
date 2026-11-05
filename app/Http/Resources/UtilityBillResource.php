<?php

namespace App\Http\Resources;

use App\Models\UtilityBill;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Amounts in santim.
 *
 * @mixin UtilityBill
 */
class UtilityBillResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'meter_id' => $this->meter_id,
            'period' => $this->period,
            'amount' => $this->amount,
            'due_date' => $this->due_date->toDateString(),
            'landlord_share' => $this->landlord_share,
            'notes' => $this->notes,
            'charges' => $this->whenLoaded('charges', fn () => $this->charges->map(fn ($c) => [
                'charge_id' => $c->id,
                'lease_id' => $c->lease_id,
                'unit_label' => $c->lease?->unit?->label,
                'tenant_name' => $c->lease?->tenant?->full_name,
                'amount' => $c->amount_due,
                'status' => $c->status,
            ])->sortBy('unit_label', SORT_NATURAL)->values()),
            'photo_url' => $this->whenLoaded('attachments', fn () => ($a = $this->attachments->first()) ? url("/api/v1/attachments/{$a->id}") : null),
            'created_at' => $this->created_at,
        ];
    }
}
