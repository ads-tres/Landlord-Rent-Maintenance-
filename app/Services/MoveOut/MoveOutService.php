<?php

namespace App\Services\MoveOut;

use App\Models\Charge;
use App\Models\Lease;
use App\Models\MoveOut;
use App\Models\MoveOutDeduction;
use App\Models\SmsMessage;
use App\Services\Payments\PaymentRecorder;
use App\Services\Payments\ReceiptData;
use App\Services\Rent\LeaseEnder;
use App\Services\Rent\RentSchedule;
use App\Services\Sms\SmsNotifier;
use App\Support\DateDisplay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Move-out and deposit settlement. The deposit first covers unpaid rent and utility shares
 * up to the move-out month (recorded as a "deposit" payment, so charges and receipts stay
 * consistent), then the landlord's other deductions (damage, cleaning, keys). What is left
 * is refunded; anything the deposit can't cover stays as a balance the tenant owes.
 */
class MoveOutService
{
    public function __construct(
        private readonly PaymentRecorder $payments,
        private readonly LeaseEnder $ender,
        private readonly SmsNotifier $sms,
        private readonly RentSchedule $schedule,
    ) {}

    public function start(Lease $lease, array $data): MoveOut
    {
        if ($lease->status !== Lease::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['lease' => __('move_out.lease_not_active')]);
        }

        return DB::transaction(function () use ($lease, $data) {
            $moveOut = MoveOut::query()->firstOrNew(['lease_id' => $lease->id]);
            $moveOut->fill($data);
            $moveOut->landlord_id = $lease->landlord_id;
            $moveOut->save();
            $this->syncChargeDeductions($moveOut);

            return $moveOut;
        });
    }

    public function update(MoveOut $moveOut, array $data): MoveOut
    {
        $this->guardDraft($moveOut);
        $moveOut->update($data);
        if (array_key_exists('moved_out_on', $data)) {
            $this->syncChargeDeductions($moveOut);
        }

        return $moveOut;
    }

    public function addDeduction(MoveOut $moveOut, array $data): MoveOutDeduction
    {
        $this->guardDraft($moveOut);
        $deduction = new MoveOutDeduction($data + ['move_out_id' => $moveOut->id]);
        $deduction->landlord_id = $moveOut->landlord_id;
        $deduction->save();

        return $deduction;
    }

    public function removeDeduction(MoveOutDeduction $deduction): void
    {
        $this->guardDraft($deduction->moveOut);
        if ($deduction->isFromCharge()) {
            // Unpaid rent is settled from the deposit or stays owed; it is not dropped here.
            throw ValidationException::withMessages(['deduction' => __('move_out.charge_deduction')]);
        }
        $deduction->delete();
    }

    /**
     * One deduction per unpaid charge up to the move-out month, with the amount still owed.
     * Re-run before settling so payments made in the meantime are taken into account.
     */
    public function syncChargeDeductions(MoveOut $moveOut): void
    {
        $moveOut->loadMissing('lease.landlord');
        $lease = $moveOut->lease;
        $locale = $lease->landlord?->locale ?: 'am';
        $calendar = $lease->landlord?->calendar_pref ?: DateDisplay::GREGORIAN;

        // Rent is owed through the move-out month, even if that charge isn't due yet.
        $this->schedule->sync($lease, $moveOut->moved_out_on);

        $charges = $lease->charges()
            ->with('utilityBill.meter')
            ->where('status', '!=', Charge::STATUS_PAID)
            ->where('period', '<=', $moveOut->moved_out_on->format('Y-m'))
            ->get()
            ->keyBy('id');

        $existing = $moveOut->deductions()->whereNotNull('charge_id')->get()->keyBy('charge_id');

        foreach ($existing as $chargeId => $deduction) {
            if (! $charges->has($chargeId)) {
                $deduction->delete();
            }
        }

        foreach ($charges as $charge) {
            $owed = $charge->amount_due - $charge->amountPaid();
            $deduction = $existing->get($charge->id);
            if ($owed <= 0) {
                $deduction?->delete();

                continue;
            }

            $period = DateDisplay::period($charge->period, $locale, $calendar);
            $fields = [
                'kind' => $charge->type === 'utility' ? 'unpaid_utility' : 'unpaid_rent',
                'description' => $charge->type === 'utility'
                    ? ($charge->utilityBill?->meter?->name ?? __('move_out.kinds.unpaid_utility', [], $locale)).' · '.$period
                    : $period,
                'amount' => $owed,
            ];

            if ($deduction) {
                $deduction->update($fields);
            } else {
                $this->addDeduction($moveOut, $fields + ['charge_id' => $charge->id]);
            }
        }
    }

    /** @return array{deposit: int, unpaid: int, other: int, applied: int, kept: int, refund: int, balance: int} */
    public function totals(MoveOut $moveOut): array
    {
        $moveOut->loadMissing(['deductions', 'lease']);
        $deposit = $moveOut->isSettled() ? $moveOut->deposit_held : (int) $moveOut->lease->deposit;
        $unpaid = (int) $moveOut->deductions->whereNotNull('charge_id')->sum('amount');
        $other = (int) $moveOut->deductions->whereNull('charge_id')->sum('amount');

        $applied = min($deposit, $unpaid);
        $kept = min($deposit - $applied, $other);

        return [
            'deposit' => $deposit,
            'unpaid' => $unpaid,
            'other' => $other,
            'applied' => $applied,
            'kept' => $kept,
            'refund' => $deposit - $applied - $kept,
            'balance' => ($unpaid - $applied) + ($other - $kept),
        ];
    }

    /** @param  array{refund_method?: ?string, refund_reference?: ?string}  $data */
    public function settle(MoveOut $moveOut, array $data, bool $notifyTenant, string $locale): MoveOut
    {
        $this->guardDraft($moveOut);

        DB::transaction(function () use ($moveOut, $data) {
            $this->syncChargeDeductions($moveOut);
            $moveOut->unsetRelation('deductions');
            $t = $this->totals($moveOut);
            $lease = $moveOut->lease;

            if ($t['refund'] > 0 && empty($data['refund_method'])) {
                throw ValidationException::withMessages(['refund_method' => __('move_out.refund_method_required')]);
            }

            if ($t['applied'] > 0) {
                $this->payments->record($lease, [
                    'amount' => $t['applied'],
                    'paid_at' => min($moveOut->moved_out_on, CarbonImmutable::today())->toDateString(),
                    'method' => 'deposit',
                    'source' => 'deposit',
                    'notes' => __('move_out.deposit_payment_note', [], $lease->landlord?->locale ?: 'am'),
                ]);
            }

            $this->ender->end($lease, CarbonImmutable::parse($moveOut->moved_out_on));

            $moveOut->forceFill([
                'status' => MoveOut::STATUS_SETTLED,
                'deposit_held' => $t['deposit'],
                'deposit_applied' => $t['applied'],
                'deposit_kept' => $t['kept'],
                'refund_amount' => $t['refund'],
                'balance_owed' => $t['balance'],
                'refund_method' => $t['refund'] > 0 ? $data['refund_method'] : null,
                'refund_reference' => $t['refund'] > 0 ? ($data['refund_reference'] ?? null) : null,
                'settled_at' => now(),
            ])->save();
        });

        if ($notifyTenant) {
            $this->notify($moveOut->refresh(), $locale);
        }

        return $moveOut;
    }

    public function notify(MoveOut $moveOut, string $locale): SmsMessage
    {
        $moveOut->loadMissing('lease.unit', 'lease.tenant');

        return $this->sms->toTenant($moveOut->lease->tenant, SmsMessage::KIND_MOVE_OUT, __('sms.move_out', [
            'unit' => $moveOut->lease->unit->label,
            'refund' => ReceiptData::birr($moveOut->refund_amount, $locale),
            'url' => $moveOut->statementUrl(),
            'app' => config('app.name'),
        ], $locale), $moveOut);
    }

    private function guardDraft(MoveOut $moveOut): void
    {
        if ($moveOut->isSettled()) {
            throw ValidationException::withMessages(['move_out' => __('move_out.already_settled')]);
        }
    }
}
