<?php

namespace App\Console\Commands;

use App\Models\Charge;
use App\Models\SmsMessage;
use App\Services\Payments\ReceiptData;
use App\Services\Sms\SmsNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Daily: texts tenants 3 days before rent is due and on the due date, for landlords
 * who keep reminders on. Each reminder is sent once per charge.
 */
#[Signature('rent:send-reminders')]
#[Description('Send SMS rent reminders 3 days before and on the due date')]
class SendRentReminders extends Command
{
    public function handle(SmsNotifier $sms): int
    {
        $sent = 0;

        $batches = [
            SmsMessage::KIND_REMINDER_BEFORE => today()->addDays(3),
            SmsMessage::KIND_REMINDER_DUE => today(),
        ];

        foreach ($batches as $kind => $date) {
            Charge::query()
                ->withoutGlobalScopes()
                ->whereIn('status', [Charge::STATUS_DUE, Charge::STATUS_PARTIAL])
                ->whereDate('due_date', $date)
                ->whereHas('landlord', fn ($q) => $q->where('sms_reminders', true))
                ->with(['landlord', 'lease' => fn ($q) => $q->withoutGlobalScopes()->with(['tenant' => fn ($t) => $t->withoutGlobalScopes(), 'unit' => fn ($u) => $u->withoutGlobalScopes()])])
                ->withSum('allocations as paid', 'amount')
                ->chunkById(200, function ($charges) use ($kind, $sms, &$sent) {
                    foreach ($charges as $charge) {
                        if ($sms->alreadySent($kind, $charge) || ! $charge->lease?->tenant) {
                            continue;
                        }

                        $locale = $charge->landlord->locale ?: 'am';
                        $body = __('sms.'.$kind, [
                            'amount' => ReceiptData::birr($charge->amount_due - (int) $charge->paid, $locale),
                            'unit' => $charge->lease->unit->label,
                            'date' => ReceiptData::date($charge->due_date, $locale, $charge->landlord->calendar_pref ?? 'gregorian'),
                            'landlord' => $charge->landlord->name ?: $charge->landlord->phone,
                            'app' => config('app.name'),
                        ], $locale);

                        $sms->queue($charge->landlord_id, $charge->lease->tenant->phone, $kind, $body, $charge);
                        $sent++;
                    }
                });
        }

        $this->info("Queued {$sent} reminder(s).");

        return self::SUCCESS;
    }
}
