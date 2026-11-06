<?php

namespace App\Services\Chapa;

use App\Models\ChapaTransaction;
use App\Models\Lease;
use App\Models\SmsMessage;
use App\Models\User;
use App\Services\Payments\PaymentRecorder;
use App\Services\Payments\ReceiptData;
use App\Services\Payments\ReceiptNotifier;
use App\Services\Sms\SmsNotifier;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Online rent through Chapa: the tenant pays, the money goes straight to the landlord's bank
 * via their sub-account (minus our flat fee and Chapa's), and we never hold it.
 * A payment is recorded only after Chapa's verify endpoint confirms success and the amount.
 */
class OnlineRent
{
    public function __construct(
        private readonly ChapaClient $chapa,
        private readonly PaymentRecorder $payments,
        private readonly ReceiptNotifier $receipts,
    ) {}

    /** @return string the Chapa checkout URL */
    public function start(Lease $lease, int $amount): string
    {
        $landlord = User::query()->findOrFail($lease->landlord_id);
        abort_unless($lease->status === Lease::STATUS_ACTIVE && $landlord->acceptsOnlineRent(), 404);

        $max = $this->asLandlord($landlord, fn () => $this->payments->maxPayable($lease));
        if ($amount < 100 || $amount > $max) {
            throw ValidationException::withMessages(['amount' => __('chapa.bad_amount')]);
        }

        $tenant = $lease->tenant()->withoutGlobalScopes()->firstOrFail();
        $tx = new ChapaTransaction([
            'lease_id' => $lease->id,
            'tenant_id' => $tenant->id,
            'tx_ref' => 'KR-'.$lease->id.'-'.Str::upper(Str::random(12)),
            'amount' => $amount,
            'fee' => (int) config('chapa.platform_fee_santim'),
        ]);
        $tx->landlord_id = $lease->landlord_id;
        $tx->save();

        try {
            $url = $this->chapa->initialize([
                'amount' => number_format($amount / 100, 2, '.', ''),
                'currency' => config('chapa.currency'),
                'tx_ref' => $tx->tx_ref,
                'phone_number' => '0'.substr($tenant->phone, 4),
                'callback_url' => route('chapa.webhook'),
                'return_url' => route('pay.done', ['token' => $lease->pay_token, 'tx_ref' => $tx->tx_ref]),
                'subaccounts' => ['id' => $landlord->payoutAccount->chapa_subaccount_id],
                'customization' => ['title' => mb_substr(config('app.name').' rent', 0, 16), 'description' => 'Rent '.$lease->id],
            ]);
        } catch (RuntimeException $e) {
            $tx->update(['status' => 'failed', 'raw' => ['error' => $e->getMessage()]]);
            Log::warning('Chapa initialize failed', ['tx_ref' => $tx->tx_ref, 'error' => $e->getMessage()]);
            throw ValidationException::withMessages(['amount' => __('chapa.unavailable')]);
        }

        $tx->update(['checkout_url' => $url]);

        return $url;
    }

    /**
     * Asks Chapa for the final state and records the payment once. Safe to call from the webhook,
     * the return page and the reconcile job, in any order and any number of times.
     */
    public function confirm(string $txRef): ?ChapaTransaction
    {
        $tx = ChapaTransaction::query()->withoutGlobalScopes()->where('tx_ref', $txRef)->first();
        if (! $tx || $tx->status === 'success') {
            return $tx;
        }

        try {
            $data = $this->chapa->verify($txRef);
        } catch (RuntimeException $e) {
            Log::warning('Chapa verify failed', ['tx_ref' => $txRef, 'error' => $e->getMessage()]);

            return $tx; // stays pending; reconcile retries
        }

        $status = strtolower((string) ($data['status'] ?? ''));
        $paid = (int) round(((float) ($data['amount'] ?? 0)) * 100);
        $currency = strtoupper((string) ($data['currency'] ?? ''));

        if ($status !== 'success') {
            if (in_array($status, ['failed', 'cancelled', 'canceled'], true)) {
                $tx->update(['status' => 'failed', 'raw' => $data]);
            }

            return $tx;
        }

        if ($paid !== $tx->amount || $currency !== config('chapa.currency')) {
            Log::error('Chapa amount mismatch', ['tx_ref' => $txRef, 'expected' => $tx->amount, 'got' => $paid, 'currency' => $currency]);
            $tx->update(['status' => 'failed', 'raw' => $data]);

            return $tx;
        }

        $landlord = User::query()->findOrFail($tx->landlord_id);
        $payment = $this->asLandlord($landlord, function () use ($tx, $data) {
            return DB::transaction(function () use ($tx, $data) {
                $locked = ChapaTransaction::query()->whereKey($tx->id)->lockForUpdate()->first();
                if ($locked->status === 'success') {
                    return null;
                }

                $lease = Lease::query()->findOrFail($tx->lease_id);
                $payment = $this->payments->record($lease, [
                    'amount' => $tx->amount,
                    'paid_at' => now()->toDateString(),
                    'method' => 'chapa',
                    'source' => 'chapa',
                    'reference' => $data['reference'] ?? $tx->tx_ref,
                    'client_ref' => $tx->tx_ref,
                ]);

                $locked->update([
                    'status' => 'success',
                    'verified_at' => now(),
                    'chapa_reference' => $data['reference'] ?? null,
                    'payment_id' => $payment->id,
                    'raw' => $data,
                ]);

                return $payment;
            });
        });

        if ($payment) {
            $locale = $landlord->locale ?: 'am';
            $this->receipts->send($payment, $locale);
            $payment->loadMissing(['tenant' => fn ($q) => $q->withoutGlobalScopes(), 'lease' => fn ($q) => $q->withoutGlobalScopes()->with(['unit' => fn ($u) => $u->withoutGlobalScopes()])]);
            app(SmsNotifier::class)->toLandlord($landlord, SmsMessage::KIND_ONLINE_PAYMENT, __('chapa.landlord_paid', [
                'tenant' => $payment->tenant?->full_name,
                'amount' => ReceiptData::birr($payment->amount, $locale),
                'unit' => $payment->lease?->unit?->label,
            ], $locale), $payment);
        }

        return $tx->refresh();
    }

    /** The rent code scopes queries to the signed-in landlord; run as the lease's landlord. */
    private function asLandlord(User $landlord, callable $fn): mixed
    {
        $previous = Auth::user();
        Auth::setUser($landlord);

        try {
            return $fn();
        } finally {
            $previous ? Auth::setUser($previous) : Auth::forgetUser();
        }
    }
}
