<?php

namespace App\Http\Resources;

use App\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Property */
class PropertyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sub_city' => $this->sub_city,
            'woreda' => $this->woreda,
            'address' => $this->address,
            'units_count' => $this->whenCounted('units'),
            'vacant_units_count' => $this->whenCounted('vacantUnits'),
            'units' => UnitResource::collection($this->whenLoaded('units')),
            'created_at' => $this->created_at,
        ];
    }
}
