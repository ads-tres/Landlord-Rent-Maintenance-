<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ChargeResource;
use App\Models\Charge;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ChargeController extends Controller
{
    /**
     * GET /charges?period=YYYY-MM&status=due|partial|paid|late|unpaid&lease_id=
     *
     * "unpaid" means due, partial or late. Without a period, all periods are returned.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'period' => ['sometimes', 'date_format:Y-m'],
            'status' => ['sometimes', Rule::in(['due', 'partial', 'paid', 'late', 'unpaid'])],
            'lease_id' => ['sometimes', 'integer'],
        ]);

        $charges = Charge::query()
            ->with(['lease.tenant', 'lease.unit.property'])
            ->withSum('allocations as amount_paid', 'amount')
            ->when($request->input('period'), fn (Builder $q, $period) => $q->where('period', $period))
            ->when($request->input('lease_id'), fn (Builder $q, $id) => $q->where('lease_id', $id))
            ->when($request->input('status'), function (Builder $q, string $status) {
                $status === 'unpaid'
                    ? $q->whereIn('status', [Charge::STATUS_DUE, Charge::STATUS_PARTIAL, Charge::STATUS_LATE])
                    : $q->where('status', $status);
            })
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();

        return ChargeResource::collection($charges);
    }
}
