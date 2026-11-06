<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Lease;
use App\Services\Portal\TenantPortal;
use Illuminate\View\View;

/**
 * The tenant's own page, /t/{token}: balance, payments with receipts, repairs, announcements.
 * No login. Open while the lease is active and for 90 days after it ends (read-only).
 */
class TenantPortalController extends Controller
{
    public function show(string $token): View
    {
        $lease = Lease::query()->withoutGlobalScopes()
            ->with([
                'unit' => fn ($q) => $q->withoutGlobalScopes()->with(['property' => fn ($p) => $p->withoutGlobalScopes()]),
                'tenant' => fn ($q) => $q->withoutGlobalScopes(),
                'landlord',
            ])
            ->where('portal_token', $token)
            ->first();

        abort_if(! $lease || ! $lease->portalOpen(), 404);

        return view('portal.show', ['p' => TenantPortal::for($lease, app()->getLocale())]);
    }
}
