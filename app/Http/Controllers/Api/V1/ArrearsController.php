<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ArrearsAction;
use App\Models\Charge;
use App\Models\Lease;
use App\Models\PaymentPlan;
use App\Services\Arrears\ArrearsService;
use App\Services\Payments\ReceiptData;
use App\Support\DateDisplay;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/** Late rent follow-up: who is behind, the suggested next step, actions and payment plans. */
class ArrearsController extends Controller
{
    public function __construct(private readonly ArrearsService $arrears) {}

    /** GET /arrears — active leases with rent overdue, most overdue first. */
    public function index(): JsonResponse
    {
        $leaseIds = Charge::query()
            ->where('due_date', '<', today())
            ->where('status', '!=', Charge::STATUS_PAID)
            ->distinct()
            ->pluck('lease_id');

        $rows = Lease::query()
            ->whereIn('id', $leaseIds)
            ->where('status', Lease::STATUS_ACTIVE)
            ->with(['unit.property', 'tenant'])
            ->get()
            ->map(fn (Lease $l) => $this->summary($l))
            ->filter(fn ($r) => $r['owed'] > 0)
            ->sortByDesc('days_overdue')
            ->values();

        return response()->json(['data' => $rows]);
    }

    /** GET /leases/{id}/arrears — overdue charges, history and the plan. */
    public function show(Lease $lease): JsonResponse
    {
        Gate::authorize('view', $lease);
        $lease->loadMissing(['unit.property', 'tenant', 'landlord']);
        $o = $this->arrears->overdue($lease);

        return response()->json(['data' => $this->summary($lease) + [
            'charges' => $o['charges']->map(fn (Charge $c) => [
                'id' => $c->id,
                'period' => $c->period,
                'type' => $c->type,
                'due_date' => $c->due_date->toDateString(),
                'owed' => $c->amount_due - (int) $c->paid,
            ])->values(),
            'actions' => ArrearsAction::query()->where('lease_id', $lease->id)->with('user:id,name')->latest('id')->limit(30)->get()
                ->map(fn (ArrearsAction $a) => [
                    'id' => $a->id,
                    'kind' => $a->kind,
                    'amount_owed' => $a->amount_owed,
                    'days_overdue' => $a->days_overdue,
                    'note' => $a->note,
                    'by' => $a->user_id && $a->user_id !== $lease->landlord_id ? $a->user?->name : null,
                    'created_at' => $a->created_at->toIso8601String(),
                ]),
        ]]);
    }

    /** POST /leases/{id}/arrears/actions {kind: reminder|notice|note, note?} */
    public function act(Request $request, Lease $lease): JsonResponse
    {
        Gate::authorize('update', $lease);
        $data = $request->validate([
            'kind' => ['required', Rule::in(['reminder', 'notice', 'note'])],
            'note' => ['required_if:kind,note', 'nullable', 'string', 'max:1000'],
        ]);

        $locale = $request->user()->actingLandlord()->locale ?: 'am';
        $data['kind'] === 'note'
            ? $this->arrears->log($lease, 'note', $request->user(), $this->arrears->overdue($lease), $data['note'])
            : $this->arrears->send($lease, $data['kind'], $request->user(), $locale);

        return $this->show($lease);
    }

    /** GET /leases/{id}/arrears/demand-letter — formal letter to print and deliver; logged. */
    public function demandLetter(Request $request, Lease $lease): SymfonyResponse
    {
        Gate::authorize('update', $lease);
        $lease->loadMissing(['unit.property', 'tenant', 'landlord']);
        $o = $this->arrears->overdue($lease);
        abort_if($o['owed'] <= 0, 422, __('arrears.nothing_owed'));

        $locale = app()->getLocale();
        $calendar = $lease->landlord?->calendar_pref ?: DateDisplay::GREGORIAN;
        $date = fn ($d) => DateDisplay::date($d, $locale, $calendar);
        $deadline = today()->addDays(15);
        $this->arrears->log($lease, 'demand_letter', $request->user(), $o);

        return Pdf::loadView('arrears.demand-letter', [
            'locale' => $locale,
            'landlord' => $lease->landlord?->name ?: $lease->landlord?->phone,
            'landlordPhone' => $lease->landlord?->phone,
            'landlordAddress' => $lease->landlord?->address,
            'tenant' => $lease->tenant->full_name,
            'place' => $lease->unit->label.' · '.$lease->unit->property->name,
            'start' => $date($lease->start_date),
            'lines' => $o['charges']->map(fn (Charge $c) => [
                'label' => $c->type === 'utility'
                    ? __('arrears.letter.utility', ['period' => DateDisplay::period($c->period, $locale, $calendar)], $locale)
                    : __('arrears.letter.rent', ['period' => DateDisplay::period($c->period, $locale, $calendar)], $locale),
                'due' => $date($c->due_date),
                'amount' => ReceiptData::birr($c->amount_due - (int) $c->paid, $locale),
            ])->all(),
            'total' => ReceiptData::birr($o['owed'], $locale),
            'deadline' => $date($deadline),
            'today' => $date(today()),
            'payUrl' => $lease->landlord?->acceptsOnlineRent() ? $lease->payUrl() : null,
        ])->setPaper('a4')->download(sprintf('demand-letter-%d.pdf', $lease->id));
    }

    /** POST /leases/{id}/payment-plan {instalments: [{due_date, amount}], notify_tenant?} */
    public function storePlan(Request $request, Lease $lease): JsonResponse
    {
        Gate::authorize('update', $lease);
        $data = $request->validate([
            'instalments' => ['required', 'array', 'min:1', 'max:12'],
            'instalments.*.due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'distinct'],
            'instalments.*.amount' => ['required', 'integer', 'min:100'],
            'notify_tenant' => ['sometimes', 'boolean'],
        ]);
        $this->arrears->createPlan($lease, $data['instalments'], $request->user(), $request->boolean('notify_tenant', true), $request->user()->locale ?: 'am');

        return $this->show($lease);
    }

    /** DELETE /payment-plans/{id} */
    public function cancelPlan(PaymentPlan $plan): Response
    {
        Gate::authorize('update', $plan);
        abort_unless($plan->status === 'active', 422);
        $plan->update(['status' => 'cancelled']);

        return response()->noContent();
    }

    private function summary(Lease $lease): array
    {
        $o = $this->arrears->overdue($lease);
        $last = ArrearsAction::query()->where('lease_id', $lease->id)->latest('id')->first();
        $plan = PaymentPlan::query()->where('lease_id', $lease->id)->where('status', 'active')->with('instalments')->latest('id')->first();

        return [
            'lease_id' => $lease->id,
            'tenant_name' => $lease->tenant?->full_name,
            'tenant_phone' => $lease->tenant?->phone,
            'unit_label' => $lease->unit?->label,
            'property_name' => $lease->unit?->property?->name,
            'owed' => $o['owed'],
            'days_overdue' => $o['days'],
            'months_behind' => $o['charges']->where('type', 'rent')->count(),
            'last_action' => $last ? ['kind' => $last->kind, 'created_at' => $last->created_at->toIso8601String()] : null,
            'suggested' => $this->arrears->suggestion($o['days'], $last, $plan),
            'plan' => $plan ? ['id' => $plan->id, 'total' => $plan->total] + $this->arrears->progress($plan) : null,
        ];
    }
}
