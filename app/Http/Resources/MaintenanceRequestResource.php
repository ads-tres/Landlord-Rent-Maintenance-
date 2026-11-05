<?php

namespace App\Http\Resources;

use App\Models\MaintenanceRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MaintenanceRequest */
class MaintenanceRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'unit_id' => $this->unit_id,
            'lease_id' => $this->lease_id,
            'title' => $this->title,
            'category' => $this->category,
            'description' => $this->description,
            'resolution_note' => $this->resolution_note,
            'status' => $this->status,
            'source' => $this->source,
            'opened_at' => $this->opened_at,
            'closed_at' => $this->closed_at,
            'unit' => $this->whenLoaded('unit', fn () => [
                'id' => $this->unit->id,
                'label' => $this->unit->label,
                'property_name' => $this->unit->relationLoaded('property') ? $this->unit->property?->name : null,
            ]),
            'tenant' => $this->whenLoaded('tenant', fn () => $this->tenant ? [
                'id' => $this->tenant->id,
                'full_name' => $this->tenant->full_name,
                'phone' => $this->tenant->phone,
            ] : null),
            'photos_count' => $this->whenCounted('attachments'),
            'photos' => $this->whenLoaded('attachments', fn () => $this->attachments->map(fn ($a) => [
                'id' => $a->id,
                'url' => url("/api/v1/attachments/{$a->id}"),
            ])),
        ];
    }
}
