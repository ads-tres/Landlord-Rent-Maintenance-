<?php

namespace App\Http\Resources;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Tenant */
class TenantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'phone' => $this->phone,
            // Only shown on the detail view, never in lists.
            'id_number' => $this->when($request->routeIs('tenants.show'), fn () => $this->id_number),
            'has_id_number' => filled($this->id_number),
            'address' => $this->address,
            'created_at' => $this->created_at,
        ];
    }
}
