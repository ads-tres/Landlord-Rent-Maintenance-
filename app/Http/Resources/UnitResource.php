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
            'created_at' => $this->created_at,
        ];
    }
}
