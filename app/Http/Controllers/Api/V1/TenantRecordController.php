<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Tenants\TenantRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TenantRecordController extends Controller
{
    /** GET /tenants/{id}/record — payment record, plus the landlord's private notes. */
    public function show(Request $request, Tenant $tenant, TenantRecord $record): JsonResponse
    {
        Gate::authorize('view', $tenant);

        return response()->json(['data' => $record->build($tenant) + [
            'notes' => $request->user()->isCaretaker() ? null : $tenant->notes,
        ]]);
    }

    /** PUT /tenants/{id}/notes {notes} */
    public function notes(Request $request, Tenant $tenant): JsonResponse
    {
        Gate::authorize('update', $tenant);
        $data = $request->validate(['notes' => ['present', 'nullable', 'string', 'max:2000']]);
        $tenant->forceFill(['notes' => filled($data['notes']) ? trim($data['notes']) : null])->save();

        return response()->json(['data' => ['notes' => $tenant->notes]]);
    }
}
