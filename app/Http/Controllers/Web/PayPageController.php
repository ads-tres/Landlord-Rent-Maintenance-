<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Charge;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\User;
use App\Services\Chapa\OnlineRent;
use App\Services\Payments\ReceiptData;
use App\Support\DateDisplay;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Tenant's online rent page (/pay/{token}): balance, then on to Chapa's checkout. No login. */
class PayPageController extends Controller
{
    public function __construct(private readonly OnlineRent $online) {}

    public function show(string $token): View
    {
        [$lease, $landlord] = $this->lease($token);
        $locale = app()->getLocale();
        $calendar = $landlord->calendar_pref ?? DateDisplay::GREGORIAN;

        $open = Charge::query()->withoutGlobalScopes()
            ->where('lease_id', $lease->id)
            ->where('status', '!=', Charge::STATUS_PAID)
            ->withSum(['allocations as paid' => fn ($q) => $q->withoutGlobalScopes()], 'amount')
            ->orderBy('due_date')
            ->get();
        $balance = (int) $open->sum(fn (Charge $c) => $c->amount_due - (int) $c->paid);
        $next = $open->first();

        return view('pay.show', [
            'lease' => $lease,
            'token' => $token,
            'balance' => $balance,
            'balanceText' => ReceiptData::birr($balance, $locale),
            'monthly' => $lease->monthly_rent,
            'monthlyText' => ReceiptData::birr($lease->monthly_rent, $locale),
            'nextDue' => $next ? DateDisplay::date($next->due_date, $locale, $calendar) : null,
            'fee' => ReceiptData::birr((int) config('chapa.platform_fee_santim'), $locale),
        ]);
    }

    public function start(Request $request, string $token): RedirectResponse
    {
        [$lease] = $this->lease($token);
        $amount = (int) $request->validate(['amount' => ['required', 'integer', 'min:100']])['amount'];

        return redirect()->away($this->online->start($lease, $amount));
    }

    /** Chapa sends the tenant back here; we verify with Chapa before saying it's paid. */
    public function done(Request $request, string $token): View
    {
        [$lease] = $this->lease($token);
        $tx = $request->query('tx_ref') ? $this->online->confirm((string) $request->query('tx_ref')) : null;
        abort_if($tx && $tx->lease_id !== $lease->id, 404);

        return view('pay.done', [
            'lease' => $lease,
            'token' => $token,
            'status' => $tx?->status ?? 'pending',
            'receiptUrl' => $tx?->payment_id ? Payment::query()->withoutGlobalScopes()->find($tx->payment_id)?->receiptUrl() : null,
        ]);
    }

    /** @return array{0: Lease, 1: User} */
    private function lease(string $token): array
    {
        $lease = Lease::query()->withoutGlobalScopes()
            ->with(['unit' => fn ($q) => $q->withoutGlobalScopes()->with(['property' => fn ($p) => $p->withoutGlobalScopes()])])
            ->where('pay_token', $token)
            ->first();
        $landlord = $lease ? User::query()->find($lease->landlord_id) : null;

        abort_unless($lease && $lease->status === Lease::STATUS_ACTIVE && $landlord?->acceptsOnlineRent(), 404);

        return [$lease, $landlord];
    }
}
