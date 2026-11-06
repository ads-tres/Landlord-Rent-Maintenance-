<?php

namespace App\Http\Resources;

use App\Models\MaintenanceRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

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
            'cost' => $this->cost,
            'status' => $this->status,
            'handled_by_name' => $this->handled_by && $this->handled_by !== $this->landlord_id ? $this->handledBy?->name : null,
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
            'photos' => $this->whenLoaded('attachments', fn () => $this->attachments
                ->filter(fn ($a) => str_starts_with($a->mime, 'image/'))
                ->values()
                ->map(fn ($a) => ['id' => $a->id, 'url' => url("/api/v1/attachments/{$a->id}")])),
            // Voice notes from Telegram, with a 30-minute link any audio player can open.
            'voice_notes' => $this->whenLoaded('attachments', fn () => $this->attachments
                ->filter(fn ($a) => str_starts_with($a->mime, 'audio/'))
                ->values()
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'url' => URL::temporarySignedRoute('attachments.signed', now()->addMinutes(30), ['attachment' => $a->id]),
                ])),
        ];
    }
}
