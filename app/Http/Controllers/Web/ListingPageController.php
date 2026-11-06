<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Resources\ListingResource;
use App\Models\Application;
use App\Models\Listing;
use App\Models\SmsMessage;
use App\Rules\EthiopianPhone;
use App\Services\Auth\OtpService;
use App\Services\Payments\ReceiptData;
use App\Services\Sms\SmsNotifier;
use App\Support\DateDisplay;
use App\Support\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Public listing page (/l/{slug}): photos, rent and an application form. The applicant
 * confirms their phone with an SMS code before the landlord sees the application.
 */
class ListingPageController extends Controller
{
    public function __construct(private readonly OtpService $otp) {}

    public function show(string $slug): View
    {
        $listing = $this->listing($slug);
        if (! $listing->isLive()) {
            abort(410);
        }

        $calendar = $listing->landlord?->calendar_pref ?? DateDisplay::GREGORIAN;
        $locale = app()->getLocale();

        return view('listings.show', [
            'listing' => $listing,
            'rent' => ReceiptData::birr($listing->asking_rent, $locale),
            'available' => DateDisplay::date($listing->available_from, $locale, $calendar),
            'photos' => ListingResource::publicPhotoUrls($listing),
        ]);
    }

    public function apply(Request $request, string $slug): RedirectResponse
    {
        $listing = $this->listing($slug);
        abort_unless($listing->isLive(), 410);

        if (is_string($request->input('phone')) && ($p = PhoneNumber::normalize($request->input('phone')))) {
            $request->merge(['phone' => $p]);
        }
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:100'],
            'phone' => ['required', new EthiopianPhone],
            'household_size' => ['nullable', 'integer', 'between:1,20'],
            'move_in_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'message' => ['nullable', 'string', 'max:500'],
            'privacy' => ['accepted'],
            'website' => ['prohibited'], // honeypot
        ]);

        $application = Application::query()->withoutGlobalScopes()->firstOrNew(['listing_id' => $listing->id, 'phone' => $data['phone']]);
        $application->forceFill([
            'landlord_id' => $listing->landlord_id,
            'full_name' => $data['full_name'],
            'household_size' => $data['household_size'] ?? null,
            'move_in_date' => $data['move_in_date'] ?? null,
            'message' => $data['message'] ?? null,
            'status' => $application->status ?? 'new',
            // Unverified applications are cleaned up after a day.
            'delete_after' => $application->phone_verified_at ? $application->delete_after : now()->addDay(),
        ])->save();

        if (! $application->phone_verified_at) {
            $this->otp->send($data['phone'], app()->getLocale(), 'listings.sms_code');
        }

        return redirect()->route('listings.public.verify', $slug)->with('apply_phone', $data['phone']);
    }

    public function showVerify(Request $request, string $slug): View|RedirectResponse
    {
        $listing = $this->listing($slug);
        $phone = $request->session()->get('apply_phone') ?? old('phone');

        return $phone ? view('listings.verify', ['listing' => $listing, 'phone' => $phone]) : redirect()->route('listings.public.show', $slug);
    }

    public function verify(Request $request, string $slug, SmsNotifier $notifier): RedirectResponse|View
    {
        $listing = $this->listing($slug);
        $data = $request->validate(['phone' => ['required', 'string'], 'code' => ['required', 'digits:6']]);

        $application = Application::query()->withoutGlobalScopes()->where('listing_id', $listing->id)->where('phone', $data['phone'])->first();
        if (! $application || ! $this->otp->verify($data['phone'], $data['code'])) {
            return redirect()->route('listings.public.verify', $slug)->with('apply_phone', $data['phone'])->withErrors(['code' => __('auth.otp_invalid')]);
        }

        $first = $application->phone_verified_at === null;
        $application->forceFill(['phone_verified_at' => $application->phone_verified_at ?? now(), 'delete_after' => null])->save();

        if ($first && $listing->landlord) {
            $notifier->toLandlord($listing->landlord, SmsMessage::KIND_LISTING, __('listings.new_application', [
                'unit' => $listing->unit->label,
                'app' => config('app.name'),
            ], $listing->landlord->locale ?: 'am'), $application);
        }

        return view('listings.sent', ['listing' => $listing]);
    }

    /** Report button: three reports hide the listing until it is reviewed. */
    public function report(string $slug): RedirectResponse
    {
        $listing = $this->listing($slug);
        $listing->increment('report_count');
        if ($listing->report_count >= Listing::REPORTS_TO_SUSPEND && $listing->status === Listing::STATUS_LIVE) {
            $listing->update(['status' => Listing::STATUS_SUSPENDED]);
            Log::warning('Listing suspended after reports', ['listing' => $listing->id, 'landlord' => $listing->landlord_id]);
        }

        return back()->with('reported', true);
    }

    private function listing(string $slug): Listing
    {
        return Listing::query()->withoutGlobalScopes()
            ->with([
                'unit' => fn ($q) => $q->withoutGlobalScopes()->with(['property' => fn ($p) => $p->withoutGlobalScopes()]),
                'landlord',
            ])
            ->where('slug', $slug)
            ->firstOrFail();
    }
}
