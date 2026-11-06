<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lease;
use App\Models\LeaseRenewal;
use App\Services\Payments\ReceiptData;
use App\Services\Rent\LeaseRenewer;
use App\Services\Rent\RentRules;
use App\Services\Rent\RentSchedule;
use App\Support\DateDisplay;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class LeaseRenewalController extends Controller
{
    /** GET /leases/{id}/renewal-check — the suggested new end and the most the rent may go up. */
    public function check(Lease $lease, RentRules $rules, RentSchedule $schedule): JsonResponse
    {
        Gate::authorize('view', $lease);
        $from = CarbonImmutable::parse($lease->end_date)->addDay();
        $rent = $schedule->rentFor($lease, $from->format('Y-m'));

        return response()->json(['data' => [
            'current_end_date' => $lease->end_date->toDateString(),
            'suggested_end_date' => $from->addYears(RentRules::MIN_LEASE_YEARS)->subDay()->toDateString(),
            'rent_from' => $from->toDateString(),
            'current_rent' => $rent,
            'max_rent' => $rules->maxAllowedRent($rent),
            'cap_percent' => $rules->capPercent(),
            'can_renew_from' => CarbonImmutable::parse($lease->end_date)->subDays(LeaseRenewer::MAX_DAYS_BEFORE_END)->toDateString(),
        ]]);
    }

    /** POST /leases/{id}/renew {new_end_date, new_rent?, notify_tenant?} */
    public function store(Request $request, Lease $lease, LeaseRenewer $renewer): JsonResponse
    {
        Gate::authorize('update', $lease);
        $data = $request->validate([
            'new_end_date' => ['required', 'date_format:Y-m-d', 'after:'.$lease->end_date->toDateString(), 'before_or_equal:'.$lease->end_date->copy()->addYears(5)->toDateString()],
            'new_rent' => ['sometimes', 'nullable', 'integer', 'min:100', 'max:10000000000'],
            'notify_tenant' => ['sometimes', 'boolean'],
        ]);

        $renewal = $renewer->renew($lease, CarbonImmutable::parse($data['new_end_date']), $data['new_rent'] ?? null);
        if ($request->boolean('notify_tenant', true)) {
            $renewer->notifyTenant($renewal, $request->user()->locale ?: 'am');
        }

        return response()->json(['data' => $this->present($renewal)], 201);
    }

    /** GET /leases/{id}/renewals */
    public function index(Lease $lease): JsonResponse
    {
        Gate::authorize('view', $lease);

        return response()->json(['data' => LeaseRenewal::query()->where('lease_id', $lease->id)->latest('id')->get()->map(fn ($r) => $this->present($r))]);
    }

    /** GET /lease-renewals/{id}/addendum — the renewal addendum to print and sign. */
    public function addendum(LeaseRenewal $renewal): Response
    {
        Gate::authorize('view', $renewal);
        $renewal->load(['lease.unit.property', 'lease.tenant', 'lease.landlord']);
        $locale = app()->getLocale();
        $calendar = $renewal->lease->landlord?->calendar_pref ?: DateDisplay::GREGORIAN;
        $date = fn ($d) => DateDisplay::date($d, $locale, $calendar);

        return Pdf::loadView('renewals.addendum', [
            'locale' => $locale,
            'r' => $renewal,
            'landlord' => $renewal->lease->landlord?->name ?: $renewal->lease->landlord?->phone,
            'tenant' => $renewal->lease->tenant->full_name,
            'place' => $renewal->lease->unit->label.' · '.$renewal->lease->unit->property->name,
            'start' => $date($renewal->lease->start_date),
            'previousEnd' => $date($renewal->previous_end_date),
            'newEnd' => $date($renewal->new_end_date),
            'rentFrom' => $date($renewal->rent_from),
            'oldRent' => ReceiptData::birr($renewal->old_rent, $locale),
            'newRent' => ReceiptData::birr($renewal->new_rent, $locale),
            'increase' => RentRules::increasePercent($renewal->old_rent, $renewal->new_rent),
            'cap' => rtrim(rtrim(number_format((float) $renewal->cap_percent, 2), '0'), '.'),
            'today' => $date(today()),
        ])->setPaper('a4')->download(sprintf('lease-renewal-%d.pdf', $renewal->id));
    }

    private function present(LeaseRenewal $r): array
    {
        return [
            'id' => $r->id,
            'lease_id' => $r->lease_id,
            'previous_end_date' => $r->previous_end_date->toDateString(),
            'new_end_date' => $r->new_end_date->toDateString(),
            'old_rent' => $r->old_rent,
            'new_rent' => $r->new_rent,
            'rent_from' => $r->rent_from->toDateString(),
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }
}
