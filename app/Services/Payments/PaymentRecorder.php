<?php

namespace App\Services\Payments;

use App\Models\Charge;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Services\Rent\RentSchedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Logs a payment and spreads it over the lease's charges: oldest unpaid month first,
 * then future months for advance payments. Amounts are in santim.
 */
class PaymentRecorder
{
    public function __construct(private readonly RentSchedule $schedule) {}

    /**
     * @param  array{amount: int, paid_at: string, method: string, reference?: ?string, notes?: ?string, client_ref?: ?string}  $data
     */
    public function record(Lease $lease, array $data): Payment
    {
        return DB::transaction(function () use ($lease, $data) {
            $this->schedule->sync($lease);

            $payment = new Payment([
                'tenant_id' => $lease->tenant_id,
                'lease_id' => $lease->id,
                'amount' => $data['amount'],
                'paid_at' => $data['paid_at'],
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'source' => 'manual',
                'receipt_token' => $this->newReceiptToken(),
                'client_ref' => $data['client_ref'] ?? null,
            ]);
            $payment->landlord_id = $lease->landlord_id;
            $payment->save();

            $remaining = (int) $data['amount'];

            // 1. Unpaid and part-paid charges (rent and utility shares), oldest due first.
            $open = $lease->charges()
                ->where('status', '!=', Charge::STATUS_PAID)
                ->orderBy('due_date')
                ->orderBy('period')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($open as $charge) {
                if ($remaining <= 0) {
                    break;
                }
                $remaining -= $this->allocate($payment, $charge, $remaining);
            }

            // 2. Advance payment: create and pay the next months of the lease.
            if ($remaining > 0) {
                $charged = $lease->charges()->where('type', 'rent')->pluck('period')->all();

                foreach ($this->schedule->periods($lease) as $period) {
                    if ($remaining <= 0) {
                        break;
                    }
                    if (in_array($period, $charged, true)) {
                        continue;
                    }
                    $remaining -= $this->allocate($payment, $this->schedule->createCharge($lease, $period), $remaining);
                }
            }

            if ($remaining > 0) {
                throw ValidationException::withMessages(['amount' => __('payments.exceeds_owed', [
                    'max' => number_format(((int) $data['amount'] - $remaining) / 100, 2),
                ])]);
            }

            return $payment->load('allocations.charge');
        });
    }

    /**
     * Removes a payment logged by mistake and puts its charges back to unpaid.
     * Future months created only for this payment are removed again.
     */
    public function void(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $charges = Charge::query()->whereIn('id', $payment->allocations()->pluck('charge_id'))->get();

            $payment->allocations()->delete();
            $payment->delete();

            $currentPeriod = now()->format('Y-m');
            foreach ($charges as $charge) {
                if ($charge->period > $currentPeriod && ! $charge->allocations()->exists()) {
                    $charge->delete();
                } else {
                    $charge->refreshStatus();
                }
            }
        });
    }

    /** Most that can be paid on the lease now: unpaid balances plus all remaining months. */
    public function maxPayable(Lease $lease): int
    {
        $open = $lease->charges()
            ->where('status', '!=', Charge::STATUS_PAID)
            ->withSum('allocations as paid', 'amount')
            ->get()
            ->sum(fn (Charge $c) => $c->amount_due - (int) $c->paid);

        $charged = $lease->charges()->where('type', 'rent')->pluck('period')->all();
        $future = collect($this->schedule->periods($lease))
            ->reject(fn ($p) => in_array($p, $charged, true))
            ->sum(fn ($p) => $this->schedule->rentFor($lease, $p));

        return (int) ($open + $future);
    }

    private function allocate(Payment $payment, Charge $charge, int $available): int
    {
        $balance = $charge->amount_due - $charge->amountPaid();
        $amount = min($balance, $available);

        if ($amount <= 0) {
            return 0;
        }

        $allocation = new PaymentAllocation(['charge_id' => $charge->id, 'amount' => $amount]);
        $allocation->landlord_id = $payment->landlord_id;
        $allocation->payment()->associate($payment);
        $allocation->save();

        $charge->refreshStatus();

        return $amount;
    }

    private function newReceiptToken(): string
    {
        do {
            $token = Str::lower(Str::random(10));
        } while (Payment::query()->withoutGlobalScopes()->where('receipt_token', $token)->exists());

        return $token;
    }
}
