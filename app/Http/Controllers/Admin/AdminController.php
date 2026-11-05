<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\User;
use App\Models\WaitlistSignup;
use App\Rules\EthiopianPhone;
use App\Services\Admin\OnboardingImport;
use App\Services\Admin\PilotMetrics;
use App\Services\Rent\RentRules;
use App\Support\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The pilot's admin panel. Reads across landlords, so queries skip the landlord scope. */
class AdminController extends Controller
{
    public function __construct(private readonly PilotMetrics $metrics) {}

    public function dashboard(): View
    {
        return view('admin.dashboard', [
            'overview' => $this->metrics->overview(),
            'landlords' => $this->metrics->landlords(),
        ]);
    }

    public function waitlist(Request $request): View
    {
        $filters = $request->validate([
            'min_units' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in(WaitlistSignup::STATUSES)],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $signups = $this->waitlistQuery($filters + ['min_units' => $request->has('min_units') ? $filters['min_units'] : 3])
            ->paginate(50)
            ->withQueryString();

        return view('admin.waitlist', ['signups' => $signups, 'filters' => $filters + ['min_units' => $request->input('min_units', 3)]]);
    }

    public function updateSignup(Request $request, WaitlistSignup $signup): RedirectResponse
    {
        $signup->update($request->validate([
            'status' => ['required', Rule::in(WaitlistSignup::STATUSES)],
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ]));

        return back()->with('status', __('admin.saved'));
    }

    public function exportWaitlist(Request $request): StreamedResponse
    {
        $rows = $this->waitlistQuery($request->only(['min_units', 'status', 'q']))->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // so Excel opens Amharic text correctly
            fputcsv($out, ['phone', 'name', 'unit_count', 'current_tracking', 'biggest_problem', 'monthly_price_birr', 'source', 'status', 'admin_notes', 'signed_up']);
            foreach ($rows as $s) {
                fputcsv($out, [$s->phone, $s->name, $s->unit_count, $s->current_tracking, $s->biggest_problem, $s->monthly_price_birr, $s->source, $s->status, $s->admin_notes, $s->created_at]);
            }
            fclose($out);
        }, 'waitlist-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Creates a pilot landlord account (from a waitlist signup or by phone). They log in with an SMS code. */
    public function storeLandlord(Request $request): RedirectResponse
    {
        if (is_string($request->input('phone')) && ($p = PhoneNumber::normalize($request->input('phone')))) {
            $request->merge(['phone' => $p]);
        }
        $data = $request->validate([
            'phone' => ['required', new EthiopianPhone],
            'name' => ['nullable', 'string', 'max:255'],
            'locale' => ['nullable', Rule::in(['am', 'en'])],
            'signup_id' => ['nullable', 'integer', 'exists:waitlist_signups,id'],
        ]);

        $user = User::query()->where('phone', $data['phone'])->first();
        if ($user?->isAdmin()) {
            return back()->withErrors(['phone' => __('admin.landlord_is_admin')]);
        }

        $user ??= new User(['phone' => $data['phone'], 'role' => User::ROLE_LANDLORD]);
        $user->fill([
            'name' => $data['name'] ?: $user->name,
            'locale' => $data['locale'] ?? ($user->locale ?: 'am'),
            'is_pilot' => true,
        ])->save();

        if (! empty($data['signup_id'])) {
            WaitlistSignup::query()->whereKey($data['signup_id'])->update(['status' => 'pilot', 'landlord_id' => $user->id]);
        }

        return redirect()->route('admin.landlords.show', $user)->with('status', __('admin.landlord_ready'));
    }

    public function showLandlord(User $landlord): View
    {
        abort_unless($landlord->role === User::ROLE_LANDLORD, 404);

        return view('admin.landlord', [
            'landlord' => $landlord,
            'row' => $this->metrics->landlords()->firstWhere('user.id', $landlord->id),
            'weeks' => $this->metrics->weeklyActive(6, $landlord->id),
            'columns' => OnboardingImport::COLUMNS,
        ]);
    }

    public function template(): Response
    {
        return response("\xEF\xBB\xBF".OnboardingImport::template(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="kiray-onboarding-template.csv"',
        ]);
    }

    /** Step 1: upload a CSV and see every row checked, before anything is saved. */
    public function previewImport(Request $request, User $landlord, OnboardingImport $import): View|RedirectResponse
    {
        abort_unless($landlord->role === User::ROLE_LANDLORD, 404);
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:1024']]);

        $result = $import->parse((string) file_get_contents($request->file('file')->getRealPath()), $landlord);
        $request->session()->put("import.{$landlord->id}", $result['rows']);

        return view('admin.import-preview', [
            'landlord' => $landlord,
            'rows' => $result['rows'],
            'rowErrors' => $result['errors'],
        ]);
    }

    /** Step 2: save the previewed rows (only offered when there are no errors). */
    public function confirmImport(Request $request, User $landlord, OnboardingImport $import): RedirectResponse
    {
        abort_unless($landlord->role === User::ROLE_LANDLORD, 404);
        $rows = $request->session()->pull("import.{$landlord->id}");
        if (! $rows) {
            return redirect()->route('admin.landlords.show', $landlord)->withErrors(['file' => __('admin.import.expired')]);
        }

        $check = $import->parse($this->rowsToCsv($rows), $landlord);
        if ($check['errors']) {
            return redirect()->route('admin.landlords.show', $landlord)->withErrors(['file' => __('admin.import.changed')]);
        }

        $counts = $import->import($check['rows'], $landlord);
        Log::info('Onboarding import', ['admin' => $request->user()->id, 'landlord' => $landlord->id] + $counts);

        return redirect()->route('admin.landlords.show', $landlord)->with('status', __('admin.import.done', $counts));
    }

    public function settings(RentRules $rules): View
    {
        return view('admin.settings', ['cap' => $rules->capPercent()]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate(['rent_cap_percent' => ['required', 'numeric', 'min:0', 'max:100']]);
        Setting::put(Setting::RENT_CAP_PERCENT, (string) $data['rent_cap_percent']);
        Log::info('Rent cap changed', ['admin' => $request->user()->id, 'cap' => $data['rent_cap_percent']]);

        return back()->with('status', __('admin.saved'));
    }

    private function waitlistQuery(array $filters)
    {
        return WaitlistSignup::query()
            ->when($filters['min_units'] ?? null, fn ($q, $n) => $q->where('unit_count', '>=', (int) $n))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('phone', 'like', "%{$term}%")->orWhere('name', 'like', "%{$term}%")->orWhere('source', 'like', "%{$term}%")))
            ->orderByDesc('unit_count')
            ->orderBy('created_at');
    }

    /** Re-validates the previewed rows on confirm, in case data changed in between. */
    private function rowsToCsv(array $rows): string
    {
        $map = [
            'monthly_rent_birr' => fn ($r) => $r['monthly_rent'] !== null ? $r['monthly_rent'] / 100 : '',
            'deposit_birr' => fn ($r) => $r['deposit'] ? $r['deposit'] / 100 : '',
            'tenant_phone' => fn ($r) => $r['tenant_phone'] ?? '',
        ];
        $lines = [implode(',', OnboardingImport::COLUMNS)];
        foreach ($rows as $r) {
            $lines[] = implode(',', array_map(function ($col) use ($r, $map) {
                $value = isset($map[$col]) ? $map[$col]($r) : ($r[$col] ?? '');

                return '"'.str_replace('"', '""', (string) $value).'"';
            }, OnboardingImport::COLUMNS));
        }

        return implode("\n", $lines);
    }
}
