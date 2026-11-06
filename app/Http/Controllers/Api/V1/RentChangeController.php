<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\LeaseResource;
use App\Models\Lease;
use App\Models\RentChange;
use App\Models\SmsMessage;
use App\Services\Payments\ReceiptData;
use App\Services\Rent\RentIncreaseNotices;
use App\Services\Rent\RentRules;
use App\Services\Rent\RentSchedule;
use App\Support\DateDisplay;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class RentChangeController extends Controller
{
    public function __construct(private RentSchedule $schedule) {}

    /**
     * DELETE /leases/{id}/rent-changes/{change} — cancels a change that hasn't started yet.
     * Charges already created for it go back to the old rent; a notified tenant is told.
     */
    public function destroy(Lease $lease, RentChange $rentChange): LeaseResource
    {
        Gate::authorize('update', $lease);
        abort_unless($rentChange->lease_id === $lease->id, 404);
        abort_unless($rentChange->effective_date->isAfter(today()), 409, __('leases.rent_change_started'));

        $notified = $rentChange->notified_at !== null;
        if ($notified) {
            app(RentIncreaseNotices::class)->send($rentChange, SmsMessage::KIND_RENT_CHANGE_CANCELLED);
        }

        DB::transaction(function () use ($lease, $rentChange) {
            $period = $rentChange->effective_date->format('Y-m');
            $rentChange->delete();
            $this->schedule->reprice($lease, $period);
        });

        return new LeaseResource($lease->refresh()->load(['unit.property', 'tenant', 'rentChanges']));
    }

    /** GET /leases/{id}/rent-changes/{change}/notice?lang=am|en|both — the letter to hand the tenant. */
    public function notice(Request $request, Lease $lease, RentChange $rentChange, RentRules $rules): Response
    {
        Gate::authorize('view', $lease);
        abort_unless($rentChange->lease_id === $lease->id, 404);
        $lang = $request->validate(['lang' => ['sometimes', 'in:am,en,both']])['lang'] ?? app()->getLocale();
        $lease->loadMissing(['unit.property', 'tenant', 'landlord']);
        $landlord = $lease->landlord;
        $calendar = $landlord?->calendar_pref ?: DateDisplay::GREGORIAN;

        $page = function (string $locale) use ($lease, $rentChange, $landlord, $calendar) {
            $date = fn ($d) => DateDisplay::date($d, $locale, $calendar);
            $increase = $rentChange->new_rent > $rentChange->old_rent;

            return [
                'locale' => $locale,
                'today' => $date(today()),
                'tenant' => $lease->tenant->full_name,
                'place' => $lease->unit->label.' · '.$lease->unit->property->name,
                'start' => $date($lease->start_date),
                'old' => ReceiptData::birr($rentChange->old_rent, $locale),
                'new' => ReceiptData::birr($rentChange->new_rent, $locale),
                'effective' => $date($rentChange->effective_date),
                'increase' => $increase,
                'percent' => rtrim(rtrim(number_format(abs(RentRules::increasePercent($rentChange->old_rent, $rentChange->new_rent)), 2), '0'), '.'),
                'cap' => rtrim(rtrim(number_format((float) $rentChange->cap_percent, 2), '0'), '.'),
                'days' => $rentChange->notice_days,
                'agreed' => $rentChange->tenant_agreed,
                'landlord' => $landlord?->name ?: $landlord?->phone,
                'landlordPhone' => $landlord?->phone,
                'landlordAddress' => $landlord?->address,
            ];
        };

        $pages = $lang === 'both' ? [$page('am'), $page('en')] : [$page($lang)];

        return Pdf::loadView('rent-changes.notice', ['pages' => $pages])
            ->setPaper('a4')
            ->download(sprintf('rent-notice-%d-%s.pdf', $lease->id, $rentChange->effective_date->format('Y-m')));
    }
}
