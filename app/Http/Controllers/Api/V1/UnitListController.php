<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;

/** GET /units — every unit with its property name, for pickers in the app. */
class UnitListController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $units = Unit::query()
            ->with(['property:id,name', 'activeLease.tenant:id,full_name'])
            ->get()
            ->sort(fn (Unit $a, Unit $b) => strnatcasecmp($a->property->name, $b->property->name)
                ?: strnatcasecmp($a->label, $b->label))
            ->values()
            ->map(fn (Unit $u) => [
                'id' => $u->id,
                'label' => $u->label,
                'status' => $u->status,
                'property_id' => $u->property_id,
                'property_name' => $u->property->name,
                'tenant_name' => $u->activeLease?->tenant?->full_name,
            ]);

        return response()->json(['data' => $units]);
    }
}
