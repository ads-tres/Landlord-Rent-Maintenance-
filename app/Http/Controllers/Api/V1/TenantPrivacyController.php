<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TenantResource;
use App\Models\Attachment;
use App\Models\Lease;
use App\Models\MaintenanceRequest;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * Tenant rights under Proclamation 1321/2024: a copy of their data, and removal of
 * personal details. Payment amounts stay, without the name, so the landlord's income
 * records for tax remain complete.
 */
class TenantPrivacyController extends Controller
{
    /** GET /tenants/{id}/export */
    public function export(Tenant $tenant): JsonResponse
    {
        Gate::authorize('view', $tenant);

        $tenant->load([
            'leases.unit.property',
            'leases.rentChanges',
            'payments.allocations.charge',
            'consents',
        ]);
        $repairs = MaintenanceRequest::query()->where('tenant_id', $tenant->id)->get();

        return response()->json([
            'exported_at' => now()->toIso8601String(),
            'tenant' => [
                'full_name' => $tenant->full_name,
                'phone' => $tenant->phone,
                'id_number' => $tenant->id_number,
                'address' => $tenant->address,
                'landlord_notes' => $tenant->notes,
                'created_at' => $tenant->created_at,
            ],
            'leases' => $tenant->leases->map(fn (Lease $l) => [
                'unit' => $l->unit?->label,
                'property' => $l->unit?->property?->name,
                'start_date' => $l->start_date->toDateString(),
                'end_date' => $l->end_date->toDateString(),
                'terminated_on' => $l->terminated_on?->toDateString(),
                'monthly_rent_santim' => $l->monthly_rent,
                'deposit_santim' => $l->deposit,
                'condition_notes' => $l->condition_notes,
                'status' => $l->status,
                'rent_changes' => $l->rentChanges->map->only(['old_rent', 'new_rent', 'effective_date', 'cap_percent']),
            ]),
            'payments' => $tenant->payments->map(fn ($p) => [
                'receipt' => $p->receiptNumber(),
                'amount_santim' => $p->amount,
                'paid_at' => $p->paid_at->toDateString(),
                'method' => $p->method,
                'reference' => $p->reference,
                'periods' => $p->allocations->map(fn ($a) => $a->charge?->period)->filter()->values(),
            ]),
            'repairs' => $repairs->map->only(['title', 'category', 'description', 'status', 'opened_at', 'closed_at']),
            'consents' => $tenant->consents->map->only(['kind', 'notice_version', 'locale', 'given_at']),
        ], 200, ['Content-Disposition' => 'attachment; filename="tenant-'.$tenant->id.'-data.json"']);
    }

    /** POST /tenants/{id}/anonymize — only when the tenant has no active lease. */
    public function anonymize(Tenant $tenant): TenantResource
    {
        Gate::authorize('update', $tenant);
        abort_if(
            $tenant->leases()->where('status', Lease::STATUS_ACTIVE)->exists(),
            409,
            __('api.tenant_has_active_lease'),
        );

        DB::transaction(function () use ($tenant) {
            $repairIds = MaintenanceRequest::query()->where('tenant_id', $tenant->id)->pluck('id');

            Attachment::query()
                ->where('attachable_type', (new MaintenanceRequest)->getMorphClass())
                ->whereIn('attachable_id', $repairIds)
                ->get()
                ->each(function (Attachment $a) {
                    Storage::disk(Attachment::DISK)->delete($a->path);
                    $a->delete();
                });

            MaintenanceRequest::query()->whereIn('id', $repairIds)->update(['description' => null]);

            $tenant->forceFill([
                'full_name' => __('api.removed_tenant', ['id' => $tenant->id]),
                'phone' => '+251000000000',
                'id_number' => null,
                'address' => null,
                'notes' => null,
            ])->save();
        });

        return new TenantResource($tenant);
    }
}
