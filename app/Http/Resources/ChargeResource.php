<?php

namespace App\Http\Resources;

use App\Models\Charge;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Amounts are integers in santim.
 *
 * @mixin Charge
 */
class ChargeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $paid = (int) ($this->amount_paid ?? 0);

        return [
            'id' => $this->id,
            'lease_id' => $this->lease_id,
            'period' => $this->period,
            'type' => $this->type,
            'amount_due' => $this->amount_due,
            'amount_paid' => $paid,
            'balance' => max($this->amount_due - $paid, 0),
            'due_date' => $this->due_date->toDateString(),
            'status' => $this->status,
            'days_late' => $this->status === Charge::STATUS_LATE
                ? (int) $this->due_date->diffInDays(today())
                : 0,
            'tenant' => $this->whenLoaded('lease', fn () => $this->lease->relationLoaded('tenant') ? [
                'id' => $this->lease->tenant->id,
                'full_name' => $this->lease->tenant->full_name,
                'phone' => $this->lease->tenant->phone,
            ] : null),
            'unit' => $this->whenLoaded('lease', fn () => $this->lease->relationLoaded('unit') ? [
                'id' => $this->lease->unit->id,
                'label' => $this->lease->unit->label,
                'property_name' => $this->lease->unit->property?->name,
            ] : null),
        ];
    }
}
