<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\EndLeaseRequest;
use App\Http\Requests\Api\RentChangeRequest;
use App\Http\Requests\Api\StoreLeaseRequest;
use App\Http\Requests\Api\UpdateLeaseRequest;
use App\Http\Resources\LeaseResource;
use App\Models\Charge;
use App\Models\Lease;
use App\Models\PaymentAllocation;
use App\Models\Unit;
use App\Services\Rent\LeaseEnder;
use App\Services\Rent\RentRules;
use App\Services\Rent\RentSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LeaseController extends Controller
{
    public function __construct(
        private readonly RentSchedule $schedule,
        private readonly RentRules $rules,
    ) {}

    /** GET /leases?status=&unit_id=&tenant_id=&ending_within_days= */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['sometimes', Rule::in([Lease::STATUS_DRAFT, Lease::STATUS_ACTIVE, Lease::STATUS_ENDED])],
            'unit_id' => ['sometimes', 'integer'],
            'tenant_id' => ['sometimes', 'integer'],
            'ending_within_days' => ['sometimes', 'integer', 'between:1,365'],
        ]);

        $leases = Lease::query()
            ->with(['unit.property', 'tenant'])
            ->when($request->input('status'), fn (Builder $q, $status) => $q->where('status', $status))
            ->when($request->input('unit_id'), fn (Builder $q, $id) => $q->where('unit_id', $id))
            ->when($request->input('tenant_id'), fn (Builder $q, $id) => $q->where('tenant_id', $id))
            ->when($request->input('ending_within_days'), fn (Builder $q, $days) => $q->endingWithin((int) $days))
            ->orderBy('end_date')
            ->get();

        return LeaseResource::collection($leases);
    }

    /** POST /leases — creates the lease, marks the unit occupied and generates charges to date. */
    public function store(StoreLeaseRequest $request): LeaseResource
    {
        $data = $request->validated();

        $lease = DB::transaction(function () use ($data) {
            $unit = Unit::query()->lockForUpdate()->findOrFail($data['unit_id']);

            if ($unit->activeLease()->exists()) {
                throw ValidationException::withMessages(['unit_id' => __('leases.unit_occupied')]);
            }

            $lease = new Lease([
                ...$data,
                'due_day' => $data['due_day'] ?? CarbonImmutable::parse($data['start_date'])->day,
                'status' => Lease::STATUS_ACTIVE,
            ]);
            $lease->landlord_id = $unit->landlord_id;
            $lease->save();

            $unit->update(['status' => Unit::STATUS_OCCUPIED]);
            $this->schedule->sync($lease);

            return $lease;
        });

        return new LeaseResource($this->loadDetail($lease));
    }

    public function show(Lease $lease): LeaseResource
    {
        Gate::authorize('view', $lease);

        return new LeaseResource($this->loadDetail($lease));
    }

    public function update(UpdateLeaseRequest $request, Lease $lease): LeaseResource
    {
        Gate::authorize('update', $lease);
        $lease->update($request->validated());

        return new LeaseResource($this->loadDetail($lease));
    }

    /**
     * POST /leases/{id}/end — ends the lease early (or on its end date), frees the unit
     * and removes unpaid charges for months after it ended.
     */
    public function end(EndLeaseRequest $request, Lease $lease): LeaseResource
    {
        Gate::authorize('update', $lease);
        abort_unless($lease->status === Lease::STATUS_ACTIVE, 409, __('leases.not_active'));

        $endedOn = CarbonImmutable::parse($request->validated('ended_on'));

        app(LeaseEnder::class)->end($lease, $endedOn);

        return new LeaseResource($this->loadDetail($lease->refresh()));
    }

    /**
     * POST /leases/{id}/rent-change — refused above the yearly cap (Proclamation 1320/2024,
     * 11.5% for 2026/27). Unpaid charges from the effective month are repriced.
     */
    public function rentChange(RentChangeRequest $request, Lease $lease): LeaseResource
    {
        Gate::authorize('update', $lease);
        abort_unless($lease->status === Lease::STATUS_ACTIVE, 409, __('leases.not_active'));

        $effective = CarbonImmutable::parse($request->validated('effective_date'));
        $newRent = (int) $request->validated('new_rent');
        $oldRent = $this->schedule->rentFor($lease, $effective->format('Y-m'));

        if ($newRent === $oldRent) {
            throw ValidationException::withMessages(['new_rent' => __('leases.rent_unchanged')]);
        }

        if ($this->rules->exceedsCap($oldRent, $newRent)) {
            throw ValidationException::withMessages(['new_rent' => __('leases.above_cap', [
                'cap' => rtrim(rtrim(number_format($this->rules->capPercent(), 2), '0'), '.'),
                'max' => number_format($this->rules->maxAllowedRent($oldRent) / 100, 2),
            ])]);
        }

        DB::transaction(function () use ($lease, $effective, $newRent, $oldRent) {
            $change = $lease->rentChanges()->make([
                'old_rent' => $oldRent,
                'new_rent' => $newRent,
                'effective_date' => $effective,
                'cap_percent' => $this->rules->capPercent(),
            ]);
            $change->landlord_id = $lease->landlord_id;
            $change->save();

            if ($effective->lessThanOrEqualTo(today())) {
                $lease->update(['monthly_rent' => $newRent]);
            }

            $this->schedule->reprice($lease, $effective->format('Y-m'));
        });

        return new LeaseResource($this->loadDetail($lease->refresh()));
    }

    /**
     * POST /leases/{id}/activate — turns a draft lease (from an approved application) into an
     * active one: the directive's condition notes are required, the unit becomes occupied and
     * rent charges start.
     */
    public function activate(Request $request, Lease $lease): LeaseResource
    {
        Gate::authorize('update', $lease);
        abort_unless($lease->status === Lease::STATUS_DRAFT, 409, __('leases.not_draft'));

        $data = $request->validate([
            'condition_notes' => ['required', 'string', 'max:5000'],
            'start_date' => ['sometimes', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'date_format:Y-m-d'],
            'monthly_rent' => ['sometimes', 'integer', 'min:100'],
            'deposit' => ['sometimes', 'integer', 'min:0'],
            'due_day' => ['sometimes', 'integer', 'between:1,30'],
        ]);

        DB::transaction(function () use ($lease, $data) {
            $unit = Unit::query()->lockForUpdate()->findOrFail($lease->unit_id);
            if ($unit->activeLease()->exists()) {
                throw ValidationException::withMessages(['unit_id' => __('leases.unit_occupied')]);
            }

            $lease->fill($data);
            $minEnd = CarbonImmutable::parse($lease->start_date)->addYears(RentRules::MIN_LEASE_YEARS)->subDay();
            if (CarbonImmutable::parse($lease->end_date)->lt($minEnd)) {
                throw ValidationException::withMessages(['end_date' => __('leases.min_term', ['years' => RentRules::MIN_LEASE_YEARS, 'date' => $minEnd->toDateString()])]);
            }
            $lease->status = Lease::STATUS_ACTIVE;
            $lease->save();

            $unit->update(['status' => Unit::STATUS_OCCUPIED]);
            $this->schedule->sync($lease);
        });

        return new LeaseResource($this->loadDetail($lease->refresh()));
    }

    private function loadDetail(Lease $lease): Lease
    {
        return $lease
            ->load(['unit.property', 'tenant', 'landlord', 'rentChanges'])
            ->loadSum('charges as charges_due_sum', 'amount_due')
            ->setAttribute('charges_paid_sum', (int) PaymentAllocation::query()
                ->whereIn('charge_id', Charge::query()->select('id')->where('lease_id', $lease->id))
                ->sum('amount'));
    }
}
