<?php

namespace App\Services\Tenants;

use App\Models\Charge;
use App\Models\Lease;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * How a tenant has paid rent, across all their leases with this landlord.
 *
 * - Only rent charges already due count.
 * - On time: fully paid by the due date (payments made ahead count from their date).
 * - Late: fully paid after the due date; days late run to the payment that cleared it.
 * - Unpaid: still owing after the due date.
 * - Rating: "new" with fewer than 3 charges due; "good" at 90%+ on time and no unpaid month;
 *   "fair" at 70%+; otherwise "poor".
 */
class TenantRecord
{
    public const MIN_CHARGES = 3;

    public function build(Tenant $tenant): array
    {
        $today = CarbonImmutable::today();
        $leases = $tenant->leases()->where('status', '!=', Lease::STATUS_DRAFT)->get();

        $charges = Charge::query()
            ->whereIn('lease_id', $leases->pluck('id'))
            ->where('type', 'rent')
            ->whereDate('due_date', '<=', $today)
            ->with('allocations.payment')
            ->orderBy('due_date')
            ->get()
            ->map(fn (Charge $c) => $this->classify($c, $today));

        $onTime = $charges->where('status', 'on_time')->count();
        $late = $charges->where('status', 'late');
        $unpaid = $charges->where('status', 'unpaid');
        $count = $charges->count();
        $percent = $count > 0 ? round($onTime / $count * 100, 1) : null;

        $first = $leases->min('start_date');

        return [
            'rating' => $this->rating($count, $percent, $unpaid->count()),
            'charges' => $count,
            'on_time' => $onTime,
            'late' => $late->count(),
            'unpaid' => $unpaid->count(),
            'on_time_percent' => $percent,
            'average_days_late' => $late->isEmpty() ? null : round($late->avg('days_late'), 1),
            'outstanding' => (int) $unpaid->sum('owed'),
            'leases' => $leases->count(),
            'tenant_since' => $first ? CarbonImmutable::parse($first)->toDateString() : null,
            // The last 12 months due, oldest first, for a strip of coloured dots.
            'recent' => $charges->slice(-12)->values()->map(fn ($c) => [
                'period' => $c['period'], 'status' => $c['status'], 'days_late' => $c['days_late'],
            ]),
        ];
    }

    /** A short version for lists (applications, renewals). */
    public function summary(Tenant $tenant): array
    {
        $r = $this->build($tenant);

        return array_intersect_key($r, array_flip(['rating', 'charges', 'on_time_percent', 'average_days_late', 'outstanding', 'tenant_since']));
    }

    /** @return array{period: string, status: string, days_late: ?int, owed: int} */
    private function classify(Charge $charge, CarbonImmutable $today): array
    {
        $due = CarbonImmutable::parse($charge->due_date);
        $paid = 0;
        $clearedOn = null;

        /** @var Collection $allocations */
        $allocations = $charge->allocations->sortBy(fn ($a) => $a->payment?->paid_at);
        foreach ($allocations as $a) {
            $paid += $a->amount;
            if ($paid >= $charge->amount_due) {
                $clearedOn = CarbonImmutable::parse($a->payment->paid_at);
                break;
            }
        }

        if ($clearedOn === null) {
            return ['period' => $charge->period, 'status' => 'unpaid', 'days_late' => (int) $due->diffInDays($today), 'owed' => $charge->amount_due - $paid];
        }

        $late = $clearedOn->greaterThan($due) ? (int) $due->diffInDays($clearedOn) : 0;

        return ['period' => $charge->period, 'status' => $late > 0 ? 'late' : 'on_time', 'days_late' => $late ?: null, 'owed' => 0];
    }

    private function rating(int $count, ?float $percent, int $unpaid): string
    {
        return match (true) {
            $count < self::MIN_CHARGES => 'new',
            $percent >= 90 && $unpaid === 0 => 'good',
            $percent >= 70 => 'fair',
            default => 'poor',
        };
    }
}
