<?php

namespace App\Http\Resources;

use App\Models\Meter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Meter */
class MeterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'property_id' => $this->property_id,
            'name' => $this->name,
            'kind' => $this->kind,
            'split_method' => $this->split_method,
            'units' => $this->whenLoaded('meterUnits', fn () => $this->meterUnits->map(fn ($mu) => [
                'unit_id' => $mu->unit_id,
                'label' => $mu->unit?->label,
                'share_percent' => $mu->share_percent,
                'occupied' => $mu->unit?->status === 'occupied',
            ])->sortBy('label', SORT_NATURAL)->values()),
            'last_bill' => $this->whenLoaded('bills', fn () => ($b = $this->bills->sortByDesc('period')->first()) ? [
                'period' => $b->period,
                'amount' => $b->amount,
            ] : null),
        ];
    }
}
