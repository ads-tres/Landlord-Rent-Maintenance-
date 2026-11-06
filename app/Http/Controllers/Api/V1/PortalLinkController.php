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

/** The tenant's page link for a lease: send it by SMS/Telegram, or replace it if it leaked. */
class PortalLinkController extends Controller
{
    /** POST /leases/{id}/portal-link/send */
    public function send(Request $request, Lease $lease, SmsNotifier $sms): JsonResponse
    {
        Gate::authorize('update', $lease);
        abort_unless($lease->portalOpen(), 409, __('leases.not_active'));

        $lease->loadMissing(['tenant', 'unit']);
        $body = __('sms.portal_link', [
            'unit' => $lease->unit->label,
            'url' => $lease->portalUrl(),
            'app' => config('app.name'),
        ], $request->user()->locale ?: 'am');

        $message = $sms->toTenant($lease->tenant, SmsMessage::KIND_PORTAL, $body, $lease);

        return response()->json(['data' => ['status' => $message->fresh()->status, 'portal_url' => $lease->portalUrl()]]);
    }

    /** POST /leases/{id}/portal-link/reset — the old link stops working. */
    public function reset(Lease $lease): LeaseResource
    {
        Gate::authorize('update', $lease);
        $lease->update(['portal_token' => Lease::newRepairToken()]);

        return new LeaseResource($lease->load(['unit.property', 'tenant', 'landlord', 'rentChanges']));
    }
}
