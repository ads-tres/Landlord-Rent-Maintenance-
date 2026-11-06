<?php

namespace App\Services\Rent;

use App\Models\RentChange;
use App\Models\SmsMessage;
use App\Services\Payments\ReceiptData;
use App\Services\Sms\SmsNotifier;
use App\Support\DateDisplay;

/** Texts the tenant about a rent change: when it is planned, a week before, and if it is cancelled. */
class RentIncreaseNotices
{
    public const REMINDER_DAYS = 7;

    public function __construct(private SmsNotifier $sms) {}

    public function send(RentChange $change, string $kind): ?SmsMessage
    {
        $change->loadMissing(['lease' => fn ($q) => $q->withoutGlobalScopes()->with([
            'tenant' => fn ($t) => $t->withoutGlobalScopes(),
            'unit' => fn ($u) => $u->withoutGlobalScopes(),
            'landlord',
        ])]);
        $lease = $change->lease;
        if (! $lease?->tenant || ! $lease->landlord) {
            return null;
        }

        $locale = $lease->landlord->locale ?: 'am';
        $body = __('sms.'.$kind, [
            'unit' => $lease->unit->label,
            'old' => ReceiptData::birr($change->old_rent, $locale),
            'new' => ReceiptData::birr($change->new_rent, $locale),
            'date' => DateDisplay::date($change->effective_date, $locale, $lease->landlord->calendar_pref ?: DateDisplay::GREGORIAN),
            'landlord' => $lease->landlord->name ?: $lease->landlord->phone,
        ], $locale);

        $message = $this->sms->toTenant($lease->tenant, $kind, $body, $change);
        if ($kind === SmsMessage::KIND_RENT_CHANGE) {
            $change->forceFill(['notified_at' => now()])->save();
        }

        return $message;
    }
}
