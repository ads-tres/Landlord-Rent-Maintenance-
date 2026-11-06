<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Charge;
use App\Models\Expense;
use App\Models\Lease;
use App\Models\MaintenanceRequest;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;

/** GET /dashboard — totals for the home screen. Amounts in santim. */
class DashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $unpaid = Charge::query()
            ->where('status', '!=', Charge::STATUS_PAID)
            ->whereDate('due_date', '<=', today())
            ->withSum('allocations as paid', 'amount')
            ->with('lease:id,tenant_id')
            ->get();

        $late = $unpaid->where('status', Charge::STATUS_LATE);

        return response()->json(['data' => [
            'month' => now()->format('Y-m'),
            'collected_this_month' => (int) Payment::query()
                ->whereBetween('paid_at', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
                ->sum('amount'),
            'expenses_this_month' => (int) Expense::query()
                ->whereBetween('spent_on', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
                ->sum('amount'),
            'due_this_month' => (int) Charge::query()->where('period', now()->format('Y-m'))->sum('amount_due'),
            'outstanding' => (int) $unpaid->sum(fn (Charge $c) => $c->amount_due - (int) $c->paid),
            'late_tenants' => $late->pluck('lease.tenant_id')->unique()->count(),
            'late_amount' => (int) $late->sum(fn (Charge $c) => $c->amount_due - (int) $c->paid),
            'open_repairs' => MaintenanceRequest::query()->where('status', '!=', 'done')->count(),
            'leases_ending_soon' => Lease::query()->endingWithin(60)->count(),
            'services_due' => Asset::query()->get()->filter(fn (Asset $a) => $a->isDueWithin(7))->count(),
            'properties' => Property::query()->count(),
            'units' => Unit::query()->count(),
            'vacant_units' => Unit::query()->where('status', Unit::STATUS_VACANT)->count(),
        ]]);
    }
}
