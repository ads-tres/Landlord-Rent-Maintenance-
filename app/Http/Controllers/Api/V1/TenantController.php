<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\TenantRequest;
use App\Http\Resources\TenantResource;
use App\Models\Consent;
use App\Models\Lease;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class TenantController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $tenants = Tenant::query()
            ->when($request->string('q')->trim()->value(), function ($query, string $q) {
                $query->where(fn ($w) => $w->where('full_name', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%"));
            })
            ->orderBy('full_name')
            ->paginate(50);

        return TenantResource::collection($tenants);
    }

    public function store(TenantRequest $request): TenantResource
    {
        $data = $request->validated();

        $tenant = DB::transaction(function () use ($data, $request) {
            $tenant = Tenant::query()->create(Arr::only($data, ['full_name', 'phone', 'id_number', 'address']));

            $tenant->consents()->create([
                'kind' => 'privacy_notice',
                'notice_version' => Consent::PRIVACY_NOTICE_VERSION,
                'locale' => $data['notice_locale'] ?? $request->user()->locale,
                'given_at' => now(),
            ]);

            return $tenant;
        });

        return new TenantResource($tenant);
    }

    public function show(Tenant $tenant): TenantResource
    {
        Gate::authorize('view', $tenant);

        return new TenantResource($tenant);
    }

    public function update(TenantRequest $request, Tenant $tenant): TenantResource
    {
        Gate::authorize('update', $tenant);
        $tenant->update(Arr::only($request->validated(), ['full_name', 'phone', 'id_number', 'address']));

        return new TenantResource($tenant);
    }

    public function destroy(Tenant $tenant): Response
    {
        Gate::authorize('delete', $tenant);

        abort_if(
            $tenant->leases()->where('status', Lease::STATUS_ACTIVE)->exists(),
            Response::HTTP_CONFLICT,
            __('api.tenant_has_active_lease'),
        );

        $tenant->delete();

        return response()->noContent();
    }
}
