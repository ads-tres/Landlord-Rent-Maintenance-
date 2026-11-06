<?php

namespace App\Http\Resources;

use App\Models\Lease;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Amounts are integers in santim.
 *
 * @mixin Lease
 */
class LeaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'unit_id' => $this->unit_id,
            'tenant_id' => $this->tenant_id,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            'terminated_on' => $this->terminated_on?->toDateString(),
            'monthly_rent' => $this->monthly_rent,
            'deposit' => $this->deposit,
            'due_day' => $this->due_day,
            'due_calendar' => $this->due_calendar,
            'condition_notes' => $this->condition_notes,
            'registered_at' => $this->registered_at?->toDateString(),
            'status' => $this->status,
            'move_out' => $this->moveOut ? ['id' => $this->moveOut->id, 'status' => $this->moveOut->status] : null,
            'pay_url' => $this->status === Lease::STATUS_ACTIVE && $request->user()?->acceptsOnlineRent() ? $this->payUrl() : null,
            'repair_url' => $this->status === Lease::STATUS_ACTIVE ? $this->repairUrl() : null,
            'days_until_end' => $this->status === Lease::STATUS_ACTIVE
                ? (int) today()->diffInDays($this->end_date, false)
                : null,
            'unit' => $this->whenLoaded('unit', fn () => [
                'id' => $this->unit->id,
                'label' => $this->unit->label,
                'property_id' => $this->unit->property_id,
                'property_name' => $this->unit->relationLoaded('property') ? $this->unit->property->name : null,
            ]),
            'tenant' => $this->whenLoaded('tenant', fn () => [
                'id' => $this->tenant->id,
                'full_name' => $this->tenant->full_name,
                'phone' => $this->tenant->phone,
            ]),
            'directive_check' => $this->when(
                $this->relationLoaded('tenant') && $this->relationLoaded('landlord'),
                fn () => $this->directiveCheck(),
            ),
            'balance' => $this->when(isset($this->charges_due_sum), fn () => [
                'charged' => (int) $this->charges_due_sum,
                'paid' => (int) ($this->charges_paid_sum ?? 0),
                'outstanding' => (int) $this->charges_due_sum - (int) ($this->charges_paid_sum ?? 0),
            ]),
            'rent_changes' => $this->whenLoaded('rentChanges', fn () => $this->rentChanges
                ->sortBy('effective_date')
                ->values()
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'old_rent' => $c->old_rent,
                    'new_rent' => $c->new_rent,
                    'effective_date' => $c->effective_date->toDateString(),
                    'cap_percent' => (float) $c->cap_percent,
                ])),
            'created_at' => $this->created_at,
        ];
    }

    /**
     * Fields the directive asks for that this lease's records are missing.
     * A warning for the landlord, not a block.
     *
     * @return array{complete: bool, missing: list<string>}
     */
    private function directiveCheck(): array
    {
        $missing = [];
        if (blank($this->landlord->name)) {
            $missing[] = 'landlord_name';
        }
        if (blank($this->tenant->address)) {
            $missing[] = 'tenant_address';
        }
        if (blank($this->condition_notes)) {
            $missing[] = 'condition_notes';
        }

        return ['complete' => $missing === [], 'missing' => $missing];
    }
}
