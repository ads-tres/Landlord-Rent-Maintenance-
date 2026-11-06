<?php

namespace App\Http\Resources;

use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Unit */
class UnitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'property_id' => $this->property_id,
            'label' => $this->label,
            'bedrooms' => $this->bedrooms,
            'status' => $this->status,
            'open_listing' => $this->whenLoaded('openListing', fn () => $this->openListing ? [
                'id' => $this->openListing->id,
                'status' => $this->openListing->status,
            ] : null),
            'active_lease' => $this->whenLoaded('activeLease', fn () => $this->activeLease ? [
                'id' => $this->activeLease->id,
                'monthly_rent' => $this->activeLease->monthly_rent,
                'end_date' => $this->activeLease->end_date->toDateString(),
                'tenant_name' => $this->activeLease->tenant?->full_name,
            ] : null),
            'created_at' => $this->created_at,
        ];
    }
}
