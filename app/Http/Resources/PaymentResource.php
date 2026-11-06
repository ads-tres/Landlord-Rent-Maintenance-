<?php

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Amounts are integers in santim.
 *
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'receipt_number' => $this->receiptNumber(),
            'lease_id' => $this->lease_id,
            'tenant_id' => $this->tenant_id,
            'amount' => $this->amount,
            'paid_at' => $this->paid_at->toDateString(),
            'method' => $this->method,
            'reference' => $this->reference,
            'notes' => $this->notes,
            'source' => $this->source,
            // Who logged it, when that was a caretaker (null for the landlord or online payments).
            'recorded_by_name' => $this->recorded_by && $this->recorded_by !== $this->landlord_id ? $this->recordedBy?->name : null,
            'client_ref' => $this->client_ref,
            'receipt_url' => $this->receipt_token ? $this->receiptUrl() : null,
            'tenant_name' => $this->whenLoaded('tenant', fn () => $this->tenant->full_name),
            'allocations' => $this->whenLoaded('allocations', fn () => $this->allocations
                ->sortBy(fn ($a) => $a->charge?->period)
                ->values()
                ->map(fn ($a) => [
                    'charge_id' => $a->charge_id,
                    'period' => $a->charge?->period,
                    'amount' => $a->amount,
                ])),
            'sms_status' => $this->when(isset($this->sms_status), fn () => $this->sms_status),
            'created_at' => $this->created_at,
        ];
    }
}
