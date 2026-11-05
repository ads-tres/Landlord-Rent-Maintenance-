<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UnitRequest;
use App\Http\Resources\UnitResource;
use App\Models\Property;
use App\Models\Unit;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class UnitController extends Controller
{
    public function index(Property $property): AnonymousResourceCollection
    {
        Gate::authorize('view', $property);

        return UnitResource::collection($property->units()->orderBy('label')->get());
    }

    public function store(UnitRequest $request, Property $property): UnitResource
    {
        Gate::authorize('update', $property);

        $unit = new Unit($request->validated());
        $unit->property()->associate($property);
        $unit->landlord_id = $property->landlord_id;
        $unit->save();

        return new UnitResource($unit);
    }

    public function show(Unit $unit): UnitResource
    {
        Gate::authorize('view', $unit);

        return new UnitResource($unit);
    }

    public function update(UnitRequest $request, Unit $unit): UnitResource
    {
        Gate::authorize('update', $unit);
        $unit->update($request->validated());

        return new UnitResource($unit);
    }

    public function destroy(Unit $unit): Response
    {
        Gate::authorize('delete', $unit);

        abort_if($unit->activeLease()->exists(), Response::HTTP_CONFLICT, __('api.unit_has_active_lease'));

        $unit->delete();

        return response()->noContent();
    }
}
