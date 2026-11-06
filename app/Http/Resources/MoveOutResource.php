<?php

namespace App\Http\Resources;

use App\Models\MoveOut;
use App\Services\MoveOut\MoveOutService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MoveOut — amounts in santim */
class MoveOutResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $t = app(MoveOutService::class)->totals($this->resource);

        return [
            'id' => $this->id,
            'lease_id' => $this->lease_id,
            'status' => $this->status,
            'moved_out_on' => $this->moved_out_on->toDateString(),
            'condition_notes' => $this->condition_notes,
            'keys_returned' => $this->keys_returned,
            'deposit' => $t['deposit'],
            'unpaid_total' => $t['unpaid'],
            'other_total' => $t['other'],
            'deposit_applied' => $t['applied'],
            'deposit_kept' => $t['kept'],
            'prepaid' => $t['prepaid'],
            'prepaid_to_unpaid' => $t['prepaid_to_unpaid'],
            'refund_amount' => $t['refund'],
            'balance_owed' => $t['balance'],
            'refund_method' => $this->refund_method,
            'refund_reference' => $this->refund_reference,
            'settled_at' => $this->settled_at?->toIso8601String(),
            'statement_url' => $this->statementUrl(),
            'deductions' => $this->deductions->map(fn ($d) => [
                'id' => $d->id,
                'kind' => $d->kind,
                'description' => $d->description,
                'amount' => $d->amount,
                'from_charge' => $d->isFromCharge(),
            ])->values(),
            'photos' => $this->whenLoaded('attachments', fn () => $this->attachments
                ->map(fn ($a) => ['id' => $a->id, 'url' => url("/api/v1/attachments/{$a->id}")])->values()),
            'tenant_name' => $this->lease->tenant?->full_name,
            'unit_label' => $this->lease->unit?->label,
            'unit_id' => $this->lease->unit_id,
        ];
    }
}
