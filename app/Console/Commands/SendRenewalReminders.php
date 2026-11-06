<?php

namespace App\Console\Commands;

use App\Models\Lease;
use App\Models\SmsMessage;
use App\Services\Sms\SmsNotifier;
use App\Support\DateDisplay;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Daily: tells landlords on Telegram when a lease ends in 60 and again in 30 days, so there is
 * time to agree a renewal (or plan a move-out). Without Telegram it shows on the home screen.
 */
#[Signature('leases:renewal-reminders')]
#[Description('Remind landlords about leases ending in 60 and 30 days')]
class SendRenewalReminders extends Command
{
    public const STAGES = [60, 30];

    public function handle(SmsNotifier $notifier): int
    {
        $sent = 0;

        Lease::query()->withoutGlobalScopes()
            ->where('status', Lease::STATUS_ACTIVE)
            ->whereBetween('end_date', [today(), today()->addDays(max(self::STAGES))])
            ->with([
                'landlord',
                'unit' => fn ($q) => $q->withoutGlobalScopes()->with(['property' => fn ($p) => $p->withoutGlobalScopes()]),
                'tenant' => fn ($q) => $q->withoutGlobalScopes(),
            ])
            ->chunkById(200, function ($leases) use ($notifier, &$sent) {
                foreach ($leases as $lease) {
                    $days = (int) today()->diffInDays($lease->end_date, false);
                    // The tightest stage reached that hasn't been sent yet.
                    $stage = collect(self::STAGES)->sort()->first(fn ($s) => $days <= $s);
                    if (! $stage || ($lease->renewal_reminder_days !== null && $lease->renewal_reminder_days <= $stage) || ! $lease->landlord) {
                        continue;
                    }

                    $locale = $lease->landlord->locale ?: 'am';
                    $message = $notifier->toLandlord($lease->landlord, SmsMessage::KIND_RENEWAL_REMINDER, __('renewals.reminder', [
                        'tenant' => $lease->tenant->full_name,
                        'unit' => $lease->unit->label,
                        'property' => $lease->unit->property->name,
                        'date' => DateDisplay::date($lease->end_date, $locale, $lease->landlord->calendar_pref ?: DateDisplay::GREGORIAN),
                        'days' => $days,
                    ], $locale), $lease);

                    $lease->forceFill(['renewal_reminder_days' => $stage])->save();
                    if ($message) {
                        $sent++;
                    }
                }
            });

        $this->info("Sent {$sent} renewal reminder(s).");

        return self::SUCCESS;
    }
}
