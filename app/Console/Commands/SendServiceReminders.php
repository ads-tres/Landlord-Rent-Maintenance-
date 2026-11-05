<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\User;
use App\Services\Sms\SmsNotifier;
use App\Support\DateDisplay;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Daily: reminds landlords on Telegram about equipment due for service within 3 days or
 * overdue, at most once a week per item. Without Telegram, it shows on the app's home screen.
 */
#[Signature('assets:remind')]
#[Description('Remind landlords about generator, water tank and pump services')]
class SendServiceReminders extends Command
{
    public function handle(SmsNotifier $notifier): int
    {
        $sent = 0;

        Asset::query()->withoutGlobalScopes()
            ->with(['property' => fn ($q) => $q->withoutGlobalScopes()])
            ->get()
            ->filter(fn (Asset $a) => $a->isDueWithin(3) && (! $a->last_reminded_on || $a->last_reminded_on->lte(today()->subDays(7))))
            ->groupBy('landlord_id')
            ->each(function ($assets, $landlordId) use ($notifier, &$sent) {
                $landlord = User::query()->find($landlordId);
                if (! $landlord) {
                    return;
                }
                $locale = $landlord->locale ?: 'am';
                $calendar = $landlord->calendar_pref ?: DateDisplay::GREGORIAN;

                foreach ($assets as $asset) {
                    $next = $asset->nextServiceOn();
                    $body = __('assets.reminder', [
                        'name' => $asset->name,
                        'property' => $asset->property?->name,
                        'date' => $next ? DateDisplay::date($next, $locale, $calendar) : __('assets.now', [], $locale),
                        'app' => config('app.name'),
                    ], $locale);

                    if ($notifier->toLandlord($landlord, 'service_reminder', $body, $asset)) {
                        $sent++;
                    }
                    $asset->forceFill(['last_reminded_on' => today()])->save();
                }
            });

        $this->info("Sent {$sent} service reminder(s).");

        return self::SUCCESS;
    }
}
