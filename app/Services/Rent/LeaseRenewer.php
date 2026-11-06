<?php

namespace App\Services\Rent;

use App\Models\Lease;
use App\Models\LeaseRenewal;
use App\Models\SmsMessage;
use App\Services\Payments\ReceiptData;
use App\Services\Sms\SmsNotifier;
use App\Support\DateDisplay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Renews a lease by extending it: same lease, payments and history, a new end date, and
 * (if it changes) the rent from the day after the old end, within the yearly legal cap.
 */
class LeaseRenewer
{
    /** Renewals can be agreed at most this long before the current end. */
    public const MAX_DAYS_BEFORE_END = 180;

    public function __construct(
        private readonly RentRules $rules,
        private readonly RentSchedule $schedule,
        private readonly SmsNotifier $sms,
    ) {}

    public function renew(Lease $lease, CarbonImmutable $newEnd, ?int $newRent): LeaseRenewal
    {
        if ($lease->status !== Lease::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['lease' => __('leases.not_active')]);
        }
        if ($lease->end_date->gt(today()->addDays(self::MAX_DAYS_BEFORE_END))) {
            throw ValidationException::withMessages(['new_end_date' => __('renewals.too_early', ['days' => self::MAX_DAYS_BEFORE_END])]);
        }

        $rentFrom = CarbonImmutable::parse($lease->end_date)->addDay();
        $oldRent = $this->schedule->rentFor($lease, $rentFrom->format('Y-m'));
        $newRent ??= $oldRent;

        if ($newRent > $oldRent && $this->rules->exceedsCap($oldRent, $newRent)) {
            throw ValidationException::withMessages(['new_rent' => __('leases.above_cap', [
                'cap' => rtrim(rtrim(number_format($this->rules->capPercent(), 2), '0'), '.'),
                'max' => number_format($this->rules->maxAllowedRent($oldRent) / 100, 2),
            ])]);
        }

        return DB::transaction(function () use ($lease, $newEnd, $newRent, $oldRent, $rentFrom) {
            $renewal = new LeaseRenewal([
                'lease_id' => $lease->id,
                'previous_end_date' => $lease->end_date,
                'new_end_date' => $newEnd,
                'old_rent' => $oldRent,
                'new_rent' => $newRent,
                'rent_from' => $rentFrom,
                'cap_percent' => $this->rules->capPercent(),
            ]);
            $renewal->landlord_id = $lease->landlord_id;
            $renewal->save();

            if ($newRent !== $oldRent) {
                $change = $lease->rentChanges()->make([
                    'old_rent' => $oldRent,
                    'new_rent' => $newRent,
                    'effective_date' => $rentFrom,
                    'cap_percent' => $this->rules->capPercent(),
                ]);
                $change->landlord_id = $lease->landlord_id;
                $change->save();
            }

            $lease->forceFill(['end_date' => $newEnd, 'renewal_reminder_days' => null])->save();
            $this->schedule->reprice($lease->refresh(), $rentFrom->format('Y-m'));

            return $renewal;
        });
    }

    public function notifyTenant(LeaseRenewal $renewal, string $locale): SmsMessage
    {
        $renewal->loadMissing(['lease.unit', 'lease.tenant', 'lease.landlord']);
        $lease = $renewal->lease;
        $calendar = $lease->landlord?->calendar_pref ?: DateDisplay::GREGORIAN;

        $body = __('sms.lease_renewed', [
            'unit' => $lease->unit->label,
            'date' => DateDisplay::date($renewal->new_end_date, $locale, $calendar),
            'rent' => ReceiptData::birr($renewal->new_rent, $locale),
            'from' => DateDisplay::date($renewal->rent_from, $locale, $calendar),
            'app' => config('app.name'),
        ], $locale);

        return $this->sms->toTenant($lease->tenant, SmsMessage::KIND_LEASE_RENEWED, $body, $renewal);
    }
}
