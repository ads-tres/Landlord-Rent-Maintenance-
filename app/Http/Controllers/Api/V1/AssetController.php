<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AssetResource;
use App\Models\Asset;
use App\Models\AssetService;
use App\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Generators, water tanks and pumps with a service interval and a service log. */
class AssetController extends Controller
{
    /** GET /assets?due_within_days= (all properties) */
    public function all(Request $request): AnonymousResourceCollection
    {
        $days = $request->integer('due_within_days', 0);
        $assets = Asset::query()->with('property')->get()
            ->when($days > 0, fn ($c) => $c->filter(fn (Asset $a) => $a->isDueWithin($days)))
            ->sortBy(fn (Asset $a) => $a->nextServiceOn()?->timestamp ?? 0)
            ->values();

        return AssetResource::collection($assets);
    }

    public function index(Property $property): AnonymousResourceCollection
    {
        Gate::authorize('view', $property);

        return AssetResource::collection($property->assets()->with('services')->orderBy('name')->get());
    }

    public function store(Request $request, Property $property): AssetResource
    {
        Gate::authorize('update', $property);
        $asset = new Asset($this->validated($request, true));
        $asset->landlord_id = $property->landlord_id;
        $asset->property()->associate($property);
        $asset->save();

        return new AssetResource($asset->load('services'));
    }

    public function update(Request $request, Asset $asset): AssetResource
    {
        Gate::authorize('update', $asset);
        $asset->update($this->validated($request, false));

        return new AssetResource($asset->load('services'));
    }

    public function destroy(Asset $asset): Response
    {
        Gate::authorize('delete', $asset);
        $asset->delete();

        return response()->noContent();
    }

    /** POST /assets/{id}/services — log a service; the next one is due an interval later. */
    public function logService(Request $request, Asset $asset): AssetResource
    {
        Gate::authorize('update', $asset);
        $data = $request->validate([
            'serviced_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'cost' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($asset, $data) {
            $service = new AssetService(['asset_id' => $asset->id] + $data);
            $service->landlord_id = $asset->landlord_id;
            $service->save();

            if (! $asset->last_serviced_on || $asset->last_serviced_on->lt($data['serviced_on'])) {
                $asset->forceFill(['last_serviced_on' => $data['serviced_on'], 'last_reminded_on' => null])->save();
            }
        });

        return new AssetResource($asset->fresh()->load('services'));
    }

    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'kind' => [$required, Rule::in(Asset::KINDS)],
            'name' => [$required, 'string', 'max:100'],
            'service_interval_days' => [$required, 'integer', 'between:7,730'],
            'last_serviced_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
    }
}
