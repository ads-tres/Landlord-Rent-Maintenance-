<?php

namespace App\Http\Resources;

use App\Models\Asset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Asset */
class AssetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $next = $this->nextServiceOn();

        return [
            'id' => $this->id,
            'property_id' => $this->property_id,
            'property_name' => $this->whenLoaded('property', fn () => $this->property->name),
            'kind' => $this->kind,
            'name' => $this->name,
            'service_interval_days' => $this->service_interval_days,
            'last_serviced_on' => $this->last_serviced_on?->toDateString(),
            'next_service_on' => $next?->toDateString(),
            'days_until_service' => $next ? (int) today()->diffInDays($next, false) : null,
            'notes' => $this->notes,
            'services' => $this->whenLoaded('services', fn () => $this->services->sortByDesc('serviced_on')->values()->map(fn ($s) => [
                'id' => $s->id,
                'serviced_on' => $s->serviced_on->toDateString(),
                'cost' => $s->cost,
                'notes' => $s->notes,
            ])),
        ];
    }
}
