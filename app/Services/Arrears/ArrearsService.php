<?php

namespace App\Services\Arrears;

use App\Models\ArrearsAction;
use App\Models\Charge;
use App\Models\Lease;
use App\Models\PaymentPlan;
use App\Models\SmsMessage;
use App\Models\User;
use App\Services\Payments\ReceiptData;
use App\Services\Sms\SmsNotifier;
use App\Support\DateDisplay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Following up late rent, politely and in steps: a friendly reminder after 15 days, a firm
 * notice after 30, and a formal demand letter after 45, or an agreed payment plan.
 * The landlord decides each step; nothing is sent automatically except plan reminders.
 */
class ArrearsService
{
    /** Days overdue at which each step is suggested. */
    public const STEPS = ['reminder' => 15, 'notice' => 30, 'demand_letter' => 45];

    public function __construct(private readonly SmsNotifier $sms) {}

    /** @return array{owed: int, days: int, charges: Collection<int, Charge>} unpaid charges past their due date */
    public function overdue(Lease $lease): array
    {
        $charges = Charge::query()
            ->where('lease_id', $lease->id)
            ->where('due_date', '<', today())
            ->withSum('allocations as paid', 'amount')
            ->orderBy('due_date')
            ->get()
            ->filter(fn (Charge $c) => $c->amount_due > (int) $c->paid)
            ->values();

        return [
            'owed' => (int) $charges->sum(fn (Charge $c) => $c->amount_due - (int) $c->paid),
            'days' => $charges->isEmpty() ? 0 : (int) $charges->first()->due_date->diffInDays(today()),
            'charges' => $charges,
        ];
    }

    /**
     * The next step to suggest: the gentlest step not yet taken, once it is late enough for it.
     * Steps go in order, so a very late tenant still gets a friendly reminder first.
     */
    public function suggestion(int $days, ?ArrearsAction $last, ?PaymentPlan $plan): ?string
    {
        if ($plan?->status === 'active') {
            return null;
        }
        $order = array_keys(self::STEPS);
        $done = $last && in_array($last->kind, $order, true) ? array_search($last->kind, $order, true) : -1;
        $next = $order[$done + 1] ?? null;

        return $next && $days >= self::STEPS[$next] ? $next : null;
    }

    /** Text the tenant a friendly reminder or a firm notice, and log it. */
    public function send(Lease $lease, string $kind, User $by, string $locale): ArrearsAction
    {
        $o = $this->overdue($lease);
        if ($o['owed'] <= 0) {
            throw ValidationException::withMessages(['kind' => __('arrears.nothing_owed')]);
        }
        $lease->loadMissing(['unit', 'tenant', 'landlord']);
        $landlord = $lease->landlord;

        $body = __('arrears.sms.'.$kind, [
            'amount' => ReceiptData::birr($o['owed'], $locale),
            'unit' => $lease->unit->label,
            'days' => $o['days'],
            'landlord' => $landlord?->name ?: $landlord?->phone,
            'url' => $lease->portalUrl() ?? '',
            'app' => config('app.name'),
        ], $locale);
        $this->sms->toTenant($lease->tenant, SmsMessage::KIND_ARREARS, trim($body), $lease);

        return $this->log($lease, $kind, $by, $o);
    }

    public function log(Lease $lease, string $kind, ?User $by, array $overdue, ?string $note = null): ArrearsAction
    {
        $action = new ArrearsAction([
            'lease_id' => $lease->id,
            'user_id' => $by?->id,
            'kind' => $kind,
            'amount_owed' => $overdue['owed'],
            'days_overdue' => $overdue['days'],
            'note' => $note,
        ]);
        $action->landlord_id = $lease->landlord_id;
        $action->save();

        return $action;
    }

    /**
     * @param  list<array{due_date: string, amount: int}>  $instalments  must add up to what is owed
     */
    public function createPlan(Lease $lease, array $instalments, User $by, bool $notify, string $locale): PaymentPlan
    {
        $o = $this->overdue($lease);
        $total = array_sum(array_column($instalments, 'amount'));
        if ($o['owed'] <= 0) {
            throw ValidationException::withMessages(['instalments' => __('arrears.nothing_owed')]);
        }
        if ($total !== $o['owed']) {
            throw ValidationException::withMessages(['instalments' => __('arrears.plan_total', ['owed' => ReceiptData::birr($o['owed'], $locale)])]);
        }

        $plan = DB::transaction(function () use ($lease, $instalments, $total, $by, $o) {
            PaymentPlan::query()->where('lease_id', $lease->id)->where('status', 'active')->update(['status' => 'cancelled']);
            $plan = new PaymentPlan(['lease_id' => $lease->id, 'total' => $total, 'starts_on' => today()]);
            $plan->landlord_id = $lease->landlord_id;
            $plan->save();
            foreach (collect($instalments)->sortBy('due_date') as $i) {
                $plan->instalments()->create(['due_date' => $i['due_date'], 'amount' => $i['amount']]);
            }
            $this->log($lease, 'plan', $by, $o, count($instalments).' × '.collect($instalments)->pluck('due_date')->implode(', '));

            return $plan;
        });

        if ($notify) {
            $lease->loadMissing(['unit', 'tenant', 'landlord']);
            $calendar = $lease->landlord?->calendar_pref ?: DateDisplay::GREGORIAN;
            $first = $plan->instalments()->first();
            $this->sms->toTenant($lease->tenant, SmsMessage::KIND_ARREARS, __('arrears.sms.plan', [
                'unit' => $lease->unit->label,
                'count' => $plan->instalments()->count(),
                'amount' => ReceiptData::birr($first->amount, $locale),
                'date' => DateDisplay::date($first->due_date, $locale, $calendar),
                'app' => config('app.name'),
            ], $locale), $plan);
        }

        return $plan;
    }

    /**
     * Progress on a plan: payments go to the oldest charges first, so what has been paid off is
     * the plan total minus what is still unpaid on the charges that were overdue when it started.
     *
     * @return array{paid: int, instalments: list<array{id: int, due_date: string, amount: int, covered: bool, overdue: bool}>, next: ?array}
     */
    public function progress(PaymentPlan $plan): array
    {
        $stillOwed = (int) Charge::query()->withoutGlobalScopes()
            ->where('lease_id', $plan->lease_id)
            ->where('due_date', '<', $plan->starts_on->toDateString())
            ->withSum('allocations as paid', 'amount')
            ->get()
            ->sum(fn (Charge $c) => max(0, $c->amount_due - (int) $c->paid));
        $paid = max(0, $plan->total - $stillOwed);

        $running = 0;
        $rows = [];
        $next = null;
        foreach ($plan->instalments as $i) {
            $running += $i->amount;
            $covered = $paid >= $running;
            $row = [
                'id' => $i->id,
                'due_date' => $i->due_date->toDateString(),
                'amount' => $i->amount,
                'covered' => $covered,
                'overdue' => ! $covered && $i->due_date->lt(today()),
            ];
            $rows[] = $row;
            if (! $covered && ! $next) {
                $next = $row;
            }
        }

        return ['paid' => min($paid, $plan->total), 'instalments' => $rows, 'next' => $next];
    }

    /** Daily: remind tenants the day before an instalment, and close plans that are done or broken. */
    public function runPlans(): array
    {
        $counts = ['reminded' => 0, 'completed' => 0, 'broken' => 0];

        PaymentPlan::query()->withoutGlobalScopes()->where('status', 'active')
            ->with(['instalments', 'lease' => fn ($q) => $q->withoutGlobalScopes()->with([
                'tenant' => fn ($t) => $t->withoutGlobalScopes(), 'unit' => fn ($u) => $u->withoutGlobalScopes(), 'landlord',
            ])])
            ->get()
            ->each(function (PaymentPlan $plan) use (&$counts) {
                $lease = $plan->lease;
                $p = $this->progress($plan);
                if ($p['next'] === null) {
                    $plan->update(['status' => 'completed']);
                    $counts['completed']++;

                    return;
                }
                // A week past an instalment without the money: the plan is broken.
                $late = collect($p['instalments'])->first(fn ($r) => ! $r['covered'] && CarbonImmutable::parse($r['due_date'])->lt(today()->subDays(7)));
                if ($late) {
                    $plan->update(['status' => 'broken']);
                    $this->log($lease, 'note', null, $this->overdue($lease), __('arrears.plan_broken', [], $lease->landlord?->locale ?: 'am'));
                    $counts['broken']++;

                    return;
                }
                $next = $plan->instalments->firstWhere('id', $p['next']['id']);
                if ($next && ! $next->reminded_at && $next->due_date->isTomorrow() && ! $lease->tenant?->reminders_opt_out) {
                    $locale = $lease->landlord?->locale ?: 'am';
                    $calendar = $lease->landlord?->calendar_pref ?: DateDisplay::GREGORIAN;
                    $this->sms->toTenant($lease->tenant, SmsMessage::KIND_ARREARS, __('arrears.sms.instalment', [
                        'amount' => ReceiptData::birr($next->amount, $locale),
                        'date' => DateDisplay::date($next->due_date, $locale, $calendar),
                        'unit' => $lease->unit->label,
                        'app' => config('app.name'),
                    ], $locale), $plan);
                    $next->update(['reminded_at' => now()]);
                    $counts['reminded']++;
                }
            });

        return $counts;
    }
}
