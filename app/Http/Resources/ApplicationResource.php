<?php

namespace App\Http\Resources;

use App\Models\Application;
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
            'created_at' => $this->created_at,
        ];
    }
}
