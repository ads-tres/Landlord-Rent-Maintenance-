<?php

namespace App\Http\Resources;

use App\Models\AssetService;
use App\Models\Expense;
use App\Models\MaintenanceRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Expense */
class ExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'property_id' => $this->property_id,
            'property_name' => $this->whenLoaded('property', fn () => $this->property?->name),
            'unit_id' => $this->unit_id,
            'unit_label' => $this->whenLoaded('unit', fn () => $this->unit?->label),
            'category' => $this->category,
            'amount' => $this->amount,
            'spent_on' => $this->spent_on->toDateString(),
            'description' => $this->description,
            'vendor' => $this->vendor,
            // Automatic expenses come from a repair, an equipment service or a utility bill;
            // they change where they came from, not here.
            'source' => $this->source_type ? [
                'type' => match ($this->source_type) {
                    MaintenanceRequest::class => 'repair',
                    AssetService::class => 'service',
                    default => 'utility_bill',
                },
                'id' => $this->source_id,
            ] : null,
            'photos' => $this->whenLoaded('attachments', fn () => $this->attachments
                ->map(fn ($a) => ['id' => $a->id, 'url' => url("/api/v1/attachments/{$a->id}")])->values()),
        ];
    }
}
