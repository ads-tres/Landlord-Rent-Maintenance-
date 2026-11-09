<?php

namespace App\Services\Rent;

use App\Models\Charge;
use App\Models\Lease;
use App\Models\SmsMessage;
use App\Models\User;
use App\Services\Payments\ReceiptData;
use App\Services\Portal\PortalLink;
use App\Services\Sms\SmsNotifier;
use App\Support\DateDisplay;
use Illuminate\Support\Collection;

/**
 * Texts every tenant who still owes rent for a month, in one go.
 *
 * Skipped: tenants who turned reminders off, and anyone already reminded this way today.
 * Messages go through the usual SMS / Telegram route, so quiet hours apply.
 */
class BulkReminder
{
    public function __construct(private SmsNotifier $sms) {}

    /**
     * One line per lease that still owes for $period.
     *
     * @return Collection<int, array{lease: Lease, owed: int, due_date: string, skip: ?string}>
     */
    public function candidates(string $period): Collection
    {
        $remindedToday = SmsMessage::query()
            ->where('kind', SmsMessage::KIND_RENT_NUDGE)
            ->where('related_type', (new Lease)->getMorphClass())
            ->whereDate('created_at', today())
            ->where('status', '!=', 'failed')
            ->pluck('related_id')
            ->all();

        return Charge::query()
            ->where('type', 'rent')
            ->where('period', $period)
            ->where('status', '!=', Charge::STATUS_PAID)
            ->whereHas('lease', fn ($q) => $q->where('status', Lease::STATUS_ACTIVE))
            ->with(['lease.tenant', 'lease.unit.property'])
            ->withSum('allocations as paid', 'amount')
            ->get()
            ->groupBy('lease_id')
            ->map(function (Collection $charges) use ($remindedToday) {
                $lease = $charges->first()->lease;

                return [
                    'lease' => $lease,
                    'owed' => (int) $charges->sum(fn (Charge $c) => $c->amount_due - (int) $c->paid),
                    'due_date' => $charges->min('due_date')->toDateString(),
                    'skip' => match (true) {
                        ! $lease->tenant => 'no_tenant',
                        (bool) $lease->tenant->reminders_opt_out => 'opted_out',
                        in_array($lease->id, $remindedToday, true) => 'reminded_today',
                        default => null,
                    },
                ];
            })
            ->filter(fn ($c) => $c['owed'] > 0)
            ->sortBy(fn ($c) => [$c['lease']->unit?->property?->name, $c['lease']->unit?->label])
            ->values();
    }

    public function message(Lease $lease, int $owed, string $period, string $dueDate, User $landlord): string
    {
        $locale = $landlord->locale ?: 'am';
        $calendar = $landlord->calendar_pref ?: DateDisplay::GREGORIAN;
        $body = __('sms.rent_nudge', [
            'amount' => ReceiptData::birr($owed, $locale),
            'unit' => $lease->unit->label,
            'month' => DateDisplay::period($period, $locale, $calendar),
            'date' => DateDisplay::date($dueDate, $locale, $calendar),
            'landlord' => $landlord->name ?: $landlord->phone,
        ], $locale);

        if ($landlord->acceptsOnlineRent() && $lease->pay_token) {
            $body .= __('chapa.pay_online', ['url' => $lease->payUrl()], $locale);
        }

        return app(PortalLink::class)->append($lease, $body, $locale);
    }

    /**
     * Sends to every candidate not skipped (only those in $leaseIds when given).
     *
     * @param  list<int>|null  $leaseIds
     * @return array{sent: int, skipped: int}
     */
    public function send(string $period, User $landlord, ?array $leaseIds = null): array
    {
        $sent = 0;
        $skipped = 0;
        foreach ($this->candidates($period) as $c) {
            if ($leaseIds !== null && ! in_array($c['lease']->id, $leaseIds, true)) {
                continue;
            }
            if ($c['skip'] !== null) {
                $skipped++;

                continue;
            }
            $this->sms->toTenant($c['lease']->tenant, SmsMessage::KIND_RENT_NUDGE, $this->message($c['lease'], $c['owed'], $period, $c['due_date'], $landlord), $c['lease']);
            $sent++;
        }

        return ['sent' => $sent, 'skipped' => $skipped];
    }
}
