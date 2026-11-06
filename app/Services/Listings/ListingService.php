<?php

namespace App\Services\Listings;

use App\Models\Application;
use App\Models\Consent;
use App\Models\Lease;
use App\Models\Listing;
use App\Models\SmsMessage;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\Payments\ReceiptData;
use App\Services\Rent\RentRules;
use App\Services\Sms\SmsNotifier;
use App\Support\DateDisplay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Vacancy listings. There is no public search: the landlord shares a link (Telegram,
 * Facebook, a broker) and approves one applicant, which creates the tenant and a draft lease.
 */
class ListingService
{
    public function __construct(
        private readonly RentRules $rules,
        private readonly SmsNotifier $sms,
    ) {}

    /** @param  array{asking_rent: int, available_from: string, description?: ?string}  $data */
    public function create(Unit $unit, array $data): Listing
    {
        if ($unit->status !== Unit::STATUS_VACANT || $unit->activeLease()->exists()) {
            throw ValidationException::withMessages(['unit' => __('listings.unit_not_vacant')]);
        }
        if ($unit->openListing()->exists()) {
            throw ValidationException::withMessages(['unit' => __('listings.already_listed')]);
        }

        $listing = new Listing($data + ['unit_id' => $unit->id]);
        $listing->landlord_id = $unit->landlord_id;
        $listing->save();

        return $listing;
    }

    /**
     * Rent law: a new tenant's rent should match the rent applied on renewal, so warn when the
     * asking rent is above the last rent plus the yearly cap. A warning, not a block.
     *
     * @return array{last_rent: ?int, max_allowed: ?int, exceeds: bool, cap_percent: float}
     */
    public function rentCheck(Listing $listing): array
    {
        $lastRent = Lease::query()
            ->where('unit_id', $listing->unit_id)
            ->whereIn('status', [Lease::STATUS_ENDED, Lease::STATUS_ACTIVE])
            ->latest('start_date')
            ->value('monthly_rent');

        $max = $lastRent ? $this->rules->maxAllowedRent((int) $lastRent) : null;

        return [
            'last_rent' => $lastRent ? (int) $lastRent : null,
            'max_allowed' => $max,
            'exceeds' => $max !== null && $listing->asking_rent > $max,
            'cap_percent' => $this->rules->capPercent(),
        ];
    }

    /** Scam control: only phone-verified landlords publish, and listings expire after 30 days. */
    public function publish(Listing $listing, User $landlord): Listing
    {
        if (! $landlord->phone_verified_at) {
            throw ValidationException::withMessages(['status' => __('listings.verify_phone_first')]);
        }
        if ($listing->unit->status !== Unit::STATUS_VACANT) {
            throw ValidationException::withMessages(['status' => __('listings.unit_not_vacant')]);
        }

        $listing->update([
            'status' => Listing::STATUS_LIVE,
            'published_at' => $listing->published_at ?? now(),
            'expires_at' => now()->addDays(Listing::DAYS_LIVE),
            'closed_at' => null,
        ]);

        return $listing;
    }

    public function close(Listing $listing): Listing
    {
        DB::transaction(function () use ($listing) {
            $listing->update(['status' => Listing::STATUS_CLOSED, 'closed_at' => now()]);
            $listing->applications()
                ->where('status', '!=', 'approved')
                ->update(['delete_after' => now()->addDays(Application::KEEP_DAYS)]);
        });

        return $listing;
    }

    /** Ready-to-post text for Telegram, Facebook or a broker, in Amharic and English. */
    public function shareText(Listing $listing): array
    {
        $listing->loadMissing('unit.property', 'landlord');
        $unit = $listing->unit;
        $calendar = $listing->landlord?->calendar_pref ?? DateDisplay::GREGORIAN;

        return collect(['am', 'en'])->mapWithKeys(fn ($locale) => [$locale => __('listings.share_text', [
            'bedrooms' => $unit->bedrooms !== null ? trans_choice('listings.bedrooms', $unit->bedrooms, ['count' => $unit->bedrooms], $locale) : __('listings.home', [], $locale),
            'area' => collect([$unit->property->sub_city, $unit->property->woreda ? __('listings.woreda', ['w' => $unit->property->woreda], $locale) : null])->filter()->implode(', ') ?: $unit->property->name,
            'rent' => ReceiptData::birr($listing->asking_rent, $locale),
            'date' => DateDisplay::date($listing->available_from, $locale, $calendar),
            'url' => $listing->url(),
        ], $locale)])->all();
    }

    public function setStatus(Application $application, string $status): Application
    {
        return match ($status) {
            'approved' => $this->approve($application),
            'rejected' => tap($application)->update(['status' => 'rejected', 'delete_after' => now()->addDays(Application::KEEP_DAYS)]),
            default => tap($application)->update(['status' => $status, 'delete_after' => null]),
        };
    }

    /**
     * Approving creates (or reuses) the tenant and a draft lease at the asking rent, closes the
     * listing, and texts the applicants: the chosen one and, briefly, the others.
     */
    public function approve(Application $application): Application
    {
        $listing = $application->listing()->with(['unit.property', 'landlord'])->firstOrFail();
        if ($listing->status === Listing::STATUS_CLOSED && $application->status !== 'approved') {
            throw ValidationException::withMessages(['status' => __('listings.closed')]);
        }
        if (! $application->phone_verified_at) {
            throw ValidationException::withMessages(['status' => __('listings.not_verified')]);
        }

        $others = DB::transaction(function () use ($application, $listing) {
            $tenant = Tenant::query()->firstOrNew(['landlord_id' => $listing->landlord_id, 'phone' => $application->phone]);
            if (! $tenant->exists) {
                $tenant->forceFill(['landlord_id' => $listing->landlord_id, 'phone' => $application->phone, 'full_name' => $application->full_name])->save();

                // The applicant saw the privacy notice on the application form.
                $consent = new Consent([
                    'tenant_id' => $tenant->id, 'kind' => 'privacy_notice', 'notice_version' => Consent::PRIVACY_NOTICE_VERSION,
                    'locale' => $listing->landlord?->locale ?? 'am', 'given_at' => $application->phone_verified_at,
                ]);
                $consent->landlord_id = $listing->landlord_id;
                $consent->save();
            }

            $start = CarbonImmutable::parse($application->move_in_date ?? $listing->available_from);
            if ($start->lt(today())) {
                $start = CarbonImmutable::today();
            }
            $lease = new Lease([
                'unit_id' => $listing->unit_id,
                'tenant_id' => $tenant->id,
                'start_date' => $start,
                'end_date' => $start->addYears(RentRules::MIN_LEASE_YEARS)->subDay(),
                'monthly_rent' => $listing->asking_rent,
                'deposit' => 0,
                'due_day' => min($start->day, 30),
                'status' => Lease::STATUS_DRAFT,
            ]);
            $lease->landlord_id = $listing->landlord_id;
            $lease->save();

            $application->update(['status' => 'approved', 'lease_id' => $lease->id, 'delete_after' => null]);
            $listing->update(['status' => Listing::STATUS_CLOSED, 'closed_at' => now()]);

            $others = $listing->applications()->whereKeyNot($application->id)->get();
            $listing->applications()->whereKeyNot($application->id)->update([
                'status' => 'rejected',
                'delete_after' => now()->addDays(Application::KEEP_DAYS),
            ]);

            return $others;
        });

        $locale = $listing->landlord?->locale ?: 'am';
        $place = $listing->unit->label.' · '.$listing->unit->property->name;
        $this->sms->queue($listing->landlord_id, $application->phone, SmsMessage::KIND_LISTING, __('listings.sms_approved', ['place' => $place, 'app' => config('app.name')], $locale), $application);
        foreach ($others->whereNotNull('phone_verified_at') as $other) {
            $this->sms->queue($listing->landlord_id, $other->phone, SmsMessage::KIND_LISTING, __('listings.sms_not_selected', ['place' => $place, 'app' => config('app.name')], $locale), $other);
        }

        return $application->refresh();
    }
}
