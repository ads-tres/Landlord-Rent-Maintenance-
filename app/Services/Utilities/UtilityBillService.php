<?php

namespace App\Services\Utilities;

use App\Models\Charge;
use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\SmsMessage;
use App\Models\UtilityBill;
use App\Services\Payments\ReceiptData;
use App\Services\Repairs\PhotoStore;
use App\Services\Sms\SmsNotifier;
use App\Support\DateDisplay;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Records a shared meter's bill and turns each occupied unit's share into a utility charge. */
class UtilityBillService
{
    public function __construct(
        private readonly UtilitySplitter $splitter,
        private readonly PhotoStore $photos,
        private readonly SmsNotifier $sms,
    ) {}

    /**
     * @param  array{period: string, amount: int, due_date: string, notes?: ?string}  $data
     * @param  array<int, array{previous: float, current: float}>  $readings
     */
    public function create(Meter $meter, array $data, array $readings, ?UploadedFile $photo, bool $notifyTenants, string $locale): UtilityBill
    {
        $split = $this->splitter->split($meter, $data['amount'], $readings);

        $bill = DB::transaction(function () use ($meter, $data, $readings, $photo, $split) {
            $bill = new UtilityBill([
                'meter_id' => $meter->id,
                'period' => $data['period'],
                'amount' => $data['amount'],
                'due_date' => $data['due_date'],
                'landlord_share' => $split['landlord_share'],
                'notes' => $data['notes'] ?? null,
            ]);
            $bill->landlord_id = $meter->landlord_id;
            $bill->save();

            foreach ($readings as $unitId => $r) {
                $reading = new MeterReading(['utility_bill_id' => $bill->id, 'unit_id' => $unitId, 'previous' => $r['previous'], 'current' => $r['current']]);
                $reading->landlord_id = $meter->landlord_id;
                $reading->save();
            }

            $due = CarbonImmutable::parse($data['due_date']);
            foreach ($split['lines'] as $line) {
                if (! $line['lease'] || $line['amount'] <= 0) {
                    continue;
                }
                $charge = new Charge([
                    'lease_id' => $line['lease']->id,
                    'period' => $data['period'],
                    'type' => 'utility',
                    'source_key' => 'bill:'.$bill->id,
                    'utility_bill_id' => $bill->id,
                    'amount_due' => $line['amount'],
                    'due_date' => $due,
                    'status' => $due->lt(today()) ? Charge::STATUS_LATE : Charge::STATUS_DUE,
                ]);
                $charge->landlord_id = $meter->landlord_id;
                $charge->save();
            }

            if ($photo) {
                $this->photos->store($photo, $bill, $meter->landlord_id);
            }

            return $bill;
        });

        if ($notifyTenants) {
            $this->notify($bill->load('charges.lease.tenant', 'charges.lease.unit', 'meter'), $locale);
        }

        return $bill;
    }

    /** Only a bill nobody has paid towards can be removed (e.g. entered with a typo). */
    public function delete(UtilityBill $bill): void
    {
        if ($bill->charges()->whereHas('allocations')->exists()) {
            throw ValidationException::withMessages(['bill' => __('utilities.bill_has_payments')]);
        }

        DB::transaction(function () use ($bill) {
            $bill->charges()->delete();
            $bill->delete();
        });
    }

    private function notify(UtilityBill $bill, string $locale): void
    {
        $calendar = $bill->meter->property?->landlord?->calendar_pref ?? DateDisplay::GREGORIAN;

        foreach ($bill->charges as $charge) {
            $body = __('sms.utility_bill', [
                'kind' => __('utilities.kinds.'.$bill->meter->kind, [], $locale),
                'period' => DateDisplay::period($bill->period, $locale, $calendar),
                'amount' => ReceiptData::birr($charge->amount_due, $locale),
                'unit' => $charge->lease->unit->label,
                'date' => DateDisplay::date($charge->due_date, $locale, $calendar),
                'app' => config('app.name'),
            ], $locale);

            $this->sms->toTenant($charge->lease->tenant, SmsMessage::KIND_UTILITY_BILL, $body, $charge);
        }
    }
}
