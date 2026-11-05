<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PropertyRequest;
use App\Http\Resources\PropertyResource;
use App\Models\Property;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class PropertyController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $properties = Property::query()
            ->withCount(['units', 'vacantUnits'])
            ->orderBy('name')
            ->get();

        return PropertyResource::collection($properties);
    }

    public function store(PropertyRequest $request): PropertyResource
    {
        $property = Property::query()->create($request->validated());

        return new PropertyResource($property->loadCount(['units', 'vacantUnits']));
    }

    public function show(Property $property): PropertyResource
    {
        Gate::authorize('view', $property);

        return new PropertyResource(
            $property->load(['units' => fn ($q) => $q->orderBy('label')])->loadCount(['units', 'vacantUnits'])
        );
    }

    public function update(PropertyRequest $request, Property $property): PropertyResource
    {
        Gate::authorize('update', $property);
        $property->update($request->validated());

        return new PropertyResource($property->loadCount(['units', 'vacantUnits']));
    }

    public function destroy(Property $property): Response
    {
        Gate::authorize('delete', $property);

        abort_if(
            $property->units()->where('status', 'occupied')->exists(),
            Response::HTTP_CONFLICT,
            __('api.property_has_occupied_units'),
        );

        $property->delete();

        return response()->noContent();
    }
}
