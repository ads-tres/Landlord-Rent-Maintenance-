<?php

namespace App\Http\Resources;

use App\Models\Application;
use App\Models\Lease;
use App\Models\Tenant;
use App\Services\Tenants\TenantRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Application */
class ApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'listing_id' => $this->listing_id,
            'full_name' => $this->full_name,
            'phone' => $this->phone,
            'household_size' => $this->household_size,
            'move_in_date' => $this->move_in_date?->toDateString(),
            'message' => $this->message,
            'status' => $this->status,
            'lease_id' => $this->lease_id,
            // Someone who rented from this landlord before: how they paid.
            'former_tenant' => $this->formerTenant(),
            'created_at' => $this->created_at,
        ];
    }

    private function formerTenant(): ?array
    {
        $tenant = Tenant::query()
            ->where('landlord_id', $this->listing?->landlord_id)
            ->where('phone', $this->phone)
            ->whereHas('leases', fn ($q) => $q->where('status', '!=', Lease::STATUS_DRAFT))
            ->first();

        return $tenant ? ['tenant_id' => $tenant->id, 'full_name' => $tenant->full_name] + app(TenantRecord::class)->summary($tenant) : null;
    }
}
