<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UtilityBillRequest;
use App\Http\Resources\UtilityBillResource;
use App\Models\Meter;
use App\Models\UtilityBill;
use App\Services\Utilities\UtilityBillService;
use App\Services\Utilities\UtilitySplitter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class UtilityBillController extends Controller
{
    public function index(Meter $meter): AnonymousResourceCollection
    {
        Gate::authorize('view', $meter);

        return UtilityBillResource::collection(
            $meter->bills()->with(['charges.lease.unit', 'charges.lease.tenant', 'attachments'])->orderByDesc('period')->limit(24)->get()
        );
    }

    /**
     * POST /meters/{id}/bills (multipart when a photo is attached).
     * With dry_run=1, returns how the bill would be split without saving anything.
     */
    public function store(UtilityBillRequest $request, Meter $meter, UtilityBillService $bills, UtilitySplitter $splitter): JsonResponse|UtilityBillResource
    {
        Gate::authorize('update', $meter);
        $data = $request->validated();

        if ($request->boolean('dry_run')) {
            $split = $splitter->split($meter, (int) $data['amount'], $request->readings());

            return response()->json(['data' => [
                'amount' => (int) $data['amount'],
                'landlord_share' => $split['landlord_share'],
                'lines' => collect($split['lines'])->map(fn ($l) => [
                    'unit_id' => $l['unit_id'],
                    'unit_label' => $l['unit_label'],
                    'tenant_name' => $l['lease']?->tenant?->full_name,
                    'amount' => $l['amount'],
                    'vacant' => $l['lease'] === null,
                ])->sortBy('unit_label', SORT_NATURAL)->values(),
            ]]);
        }

        $bill = $bills->create(
            $meter,
            ['period' => $data['period'], 'amount' => (int) $data['amount'], 'due_date' => $data['due_date'], 'notes' => $data['notes'] ?? null],
            $request->readings(),
            $request->file('photo'),
            $request->boolean('notify_tenants', true),
            $request->user()->locale,
        );

        return new UtilityBillResource($bill->load(['charges.lease.unit', 'charges.lease.tenant', 'attachments']));
    }

    public function destroy(UtilityBill $bill, UtilityBillService $bills): Response
    {
        Gate::authorize('delete', $bill);
        $bills->delete($bill);

        return response()->noContent();
    }
}
