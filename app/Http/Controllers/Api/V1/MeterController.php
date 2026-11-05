<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\MeterRequest;
use App\Http\Resources\MeterResource;
use App\Models\Meter;
use App\Models\MeterUnit;
use App\Models\Property;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class MeterController extends Controller
{
    public function index(Property $property): AnonymousResourceCollection
    {
        Gate::authorize('view', $property);

        return MeterResource::collection($property->meters()->with(['meterUnits.unit', 'bills'])->orderBy('name')->get());
    }

    public function store(MeterRequest $request, Property $property): MeterResource
    {
        Gate::authorize('update', $property);

        $meter = DB::transaction(function () use ($request, $property) {
            $meter = new Meter($request->safe()->only(['name', 'kind', 'split_method']));
            $meter->landlord_id = $property->landlord_id;
            $meter->property()->associate($property);
            $meter->save();
            $this->syncUnits($meter, $request->validated('units'));

            return $meter;
        });

        return new MeterResource($meter->load(['meterUnits.unit', 'bills']));
    }

    public function show(Meter $meter): MeterResource
    {
        Gate::authorize('view', $meter);

        return new MeterResource($meter->load(['meterUnits.unit', 'bills']));
    }

    public function update(MeterRequest $request, Meter $meter): MeterResource
    {
        Gate::authorize('update', $meter);

        DB::transaction(function () use ($request, $meter) {
            $meter->update($request->safe()->only(['name', 'kind', 'split_method']));
            if ($request->has('units')) {
                $this->syncUnits($meter, $request->validated('units'));
            }
        });

        return new MeterResource($meter->load(['meterUnits.unit', 'bills']));
    }

    public function destroy(Meter $meter): Response
    {
        Gate::authorize('delete', $meter);
        abort_if($meter->bills()->exists(), 409, __('utilities.meter_has_bills'));

        $meter->delete();

        return response()->noContent();
    }

    private function syncUnits(Meter $meter, array $units): void
    {
        $meter->meterUnits()->delete();
        foreach ($units as $u) {
            $row = new MeterUnit(['meter_id' => $meter->id, 'unit_id' => $u['unit_id'], 'share_percent' => $u['share_percent'] ?? null]);
            $row->landlord_id = $meter->landlord_id;
            $row->save();
        }
    }
}
