<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\LeaseResource;
use App\Models\Lease;
use App\Models\SmsMessage;
use App\Services\Sms\SmsNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** The tenant's repair link for a lease: send it by SMS, or replace it if it leaked. */
class RepairLinkController extends Controller
{
    /** POST /leases/{id}/repair-link/send */
    public function send(Request $request, Lease $lease, SmsNotifier $sms): JsonResponse
    {
        Gate::authorize('update', $lease);
        abort_unless($lease->status === Lease::STATUS_ACTIVE, 409, __('leases.not_active'));

        $lease->loadMissing(['tenant', 'unit']);
        $locale = $request->user()->locale;
        $body = __('sms.repair_link', [
            'unit' => $lease->unit->label,
            'url' => $lease->repairUrl(),
            'app' => config('app.name'),
        ], $locale);

        $message = $sms->queue($lease->landlord_id, $lease->tenant->phone, SmsMessage::KIND_REPAIR_LINK, $body, $lease);

        return response()->json(['data' => ['status' => $message->fresh()->status, 'repair_url' => $lease->repairUrl()]]);
    }

    /** POST /leases/{id}/repair-link/reset — the old link stops working. */
    public function reset(Lease $lease): LeaseResource
    {
        Gate::authorize('update', $lease);

        $lease->update(['repair_token' => Lease::newRepairToken()]);

        return new LeaseResource($lease->load(['unit.property', 'tenant', 'landlord', 'rentChanges']));
    }
}
