<?php

namespace App\Console\Commands;

use App\Models\RentChange;
use App\Models\SmsMessage;
use App\Services\Rent\RentIncreaseNotices;
use App\Services\Sms\SmsNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Daily: reminds tenants a week before a rent increase starts. Sent once per change. */
#[Signature('rent:change-reminders')]
#[Description('Text tenants a week before a rent increase starts')]
class SendRentChangeReminders extends Command
{
    public function handle(RentIncreaseNotices $notices, SmsNotifier $sms): int
    {
        $sent = 0;

        RentChange::query()
            ->withoutGlobalScopes()
            ->whereDate('effective_date', today()->addDays(RentIncreaseNotices::REMINDER_DAYS))
            ->whereColumn('new_rent', '>', 'old_rent')
            ->whereNotNull('notified_at')
            ->whereHas('lease', fn ($q) => $q->withoutGlobalScopes()->where('status', 'active'))
            ->each(function (RentChange $change) use ($notices, $sms, &$sent) {
                if ($sms->alreadySent(SmsMessage::KIND_RENT_CHANGE_REMINDER, $change)) {
                    return;
                }
                if ($notices->send($change, SmsMessage::KIND_RENT_CHANGE_REMINDER)) {
                    $sent++;
                }
            });

        $this->info("Queued {$sent} reminder(s).");

        return self::SUCCESS;
    }
}
