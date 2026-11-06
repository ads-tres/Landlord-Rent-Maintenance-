<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\Listing;
use App\Models\SmsMessage;
use App\Models\Unit;
use App\Models\User;
use App\Services\Sms\SmsNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Daily: closes listings after 30 days, deletes applications that weren't approved once
 * their keep period ends (privacy), and reminds landlords about units empty for 3 and 5
 * months (directive 7/2024: over 6 months unrented is a violation).
 */
#[Signature('listings:daily')]
#[Description('Expire listings, purge old applications and send vacancy reminders')]
class ListingMaintenance extends Command
{
    public function handle(SmsNotifier $notifier): int
    {
        $expired = Listing::query()->withoutGlobalScopes()
            ->where('status', Listing::STATUS_LIVE)
            ->where('expires_at', '<', now())
            ->update(['status' => Listing::STATUS_CLOSED, 'closed_at' => now()]);

        $purged = Application::query()->withoutGlobalScopes()
            ->where('status', '!=', 'approved')
            ->whereNotNull('delete_after')
            ->where('delete_after', '<', now())
            ->delete();

        $reminded = 0;
        Unit::query()->withoutGlobalScopes()
            ->with(['property' => fn ($q) => $q->withoutGlobalScopes()])
            ->where('status', Unit::STATUS_VACANT)
            ->whereNotNull('vacant_since')
            ->get()
            ->each(function (Unit $unit) use ($notifier, &$reminded) {
                $months = (int) floor($unit->vacant_since->diffInMonths(now()));
                $milestone = $months >= 5 ? 5 : ($months >= 3 ? 3 : 0);
                if ($milestone === 0 || $unit->vacancy_reminded_months >= $milestone) {
                    return;
                }

                $landlord = User::query()->find($unit->landlord_id);
                if ($landlord) {
                    $locale = $landlord->locale ?: 'am';
                    $notifier->toLandlord($landlord, SmsMessage::KIND_LISTING, __('listings.vacancy_reminder', [
                        'unit' => $unit->label,
                        'property' => $unit->property?->name,
                        'months' => $milestone,
                        'app' => config('app.name'),
                    ], $locale), $unit);
                    $reminded++;
                }
                $unit->forceFill(['vacancy_reminded_months' => $milestone])->saveQuietly();
            });

        $this->info("Expired {$expired} listing(s), deleted {$purged} application(s), {$reminded} vacancy reminder(s).");

        return self::SUCCESS;
    }
}
