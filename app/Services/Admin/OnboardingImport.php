<?php

namespace App\Services\Admin;

use App\Models\Charge;
use App\Models\Consent;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\Payments\PaymentRecorder;
use App\Services\Rent\RentRules;
use App\Services\Rent\RentSchedule;
use App\Support\EthiopianDate;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Imports a landlord's notebook from a spreadsheet (CSV), so the pilot setup the guide
 * recommends ("enter their units, tenants and leases yourself") takes minutes.
 *
 * One row per unit. Leave the tenant columns empty for a vacant unit.
 * Dates are Gregorian (2026-11-15), or Ethiopian with an "EC" / "ዓ.ም" suffix (2019-03-06 EC).
 * paid_through (YYYY-MM, Gregorian month) records rent already paid as one opening payment.
 */
class OnboardingImport
{
    public const COLUMNS = [
        'property', 'sub_city', 'woreda', 'unit', 'bedrooms',
        'tenant_name', 'tenant_phone', 'tenant_address',
        'monthly_rent_birr', 'deposit_birr', 'lease_start', 'lease_end', 'due_day',
        'condition_notes', 'paid_through',
    ];

    public function __construct(
        private readonly RentSchedule $schedule,
        private readonly PaymentRecorder $payments,
    ) {}

    public static function template(): string
    {
        $rows = [
            self::COLUMNS,
            ['Bole Compound', 'Bole', '3', 'Room 1', '1', 'Almaz Tesfaye', '0911234567', 'Bole, Woreda 3', '12000', '24000', '2026-09-01', '', '1', 'Painted, all fixtures working', '2026-11'],
            ['Bole Compound', 'Bole', '3', 'Room 2', '2', 'Dawit Haile', '0922334455', 'Bole', '15000', '30000', '2019-01-01 EC', '', '', '', ''],
            ['Bole Compound', 'Bole', '3', 'Room 3', '1', '', '', '', '', '', '', '', '', '', ''],
        ];

        return implode("\n", array_map(fn ($r) => implode(',', array_map(fn ($v) => str_contains($v, ',') ? "\"{$v}\"" : $v, $r)), $rows))."\n";
    }

    /**
     * Parses and validates a CSV. Nothing is saved.
     *
     * @return array{rows: list<array<string, mixed>>, errors: array<int, list<string>>}
     */
    public function parse(string $csv, User $landlord): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv); // Excel BOM
        $lines = array_values(array_filter(preg_split('/\r\n|\n|\r/', trim((string) $csv)), fn ($l) => trim($l) !== ''));
        if ($lines === []) {
            return ['rows' => [], 'errors' => [0 => [__('admin.import.empty')]]];
        }

        $header = array_map(fn ($h) => strtolower(trim($h)), str_getcsv(array_shift($lines)));
        $missing = array_diff(['property', 'unit'], $header);
        if ($missing) {
            return ['rows' => [], 'errors' => [0 => [__('admin.import.missing_columns', ['columns' => implode(', ', $missing)])]]];
        }

        $rows = [];
        $errors = [];
        $seenUnits = [];

        foreach ($lines as $i => $line) {
            $lineNo = $i + 2;
            $values = str_getcsv($line);
            $raw = [];
            foreach (self::COLUMNS as $col) {
                $index = array_search($col, $header, true);
                $raw[$col] = $index === false ? '' : trim((string) ($values[$index] ?? ''));
            }

            [$row, $rowErrors] = $this->validateRow($raw, $landlord);
            $key = mb_strtolower($row['property'].'|'.$row['unit']);
            if (isset($seenUnits[$key])) {
                $rowErrors[] = __('admin.import.duplicate_unit', ['line' => $seenUnits[$key]]);
            }
            $seenUnits[$key] = $lineNo;

            $row['line'] = $lineNo;
            $rows[] = $row;
            if ($rowErrors) {
                $errors[$lineNo] = $rowErrors;
            }
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * Saves parsed rows for the landlord in one transaction.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{properties: int, units: int, tenants: int, leases: int, payments: int}
     */
    public function import(array $rows, User $landlord): array
    {
        $counts = ['properties' => 0, 'units' => 0, 'tenants' => 0, 'leases' => 0, 'payments' => 0];

        // The rent schedule and payment code scope queries to the signed-in landlord,
        // so run the import as that landlord and switch back afterwards.
        $previous = Auth::user();
        Auth::setUser($landlord);

        try {
            $this->importRows($rows, $landlord, $counts);
        } finally {
            $previous ? Auth::setUser($previous) : Auth::forgetUser();
        }

        return $counts;
    }

    /** @param  array<string, int>  $counts */
    private function importRows(array $rows, User $landlord, array &$counts): void
    {
        DB::transaction(function () use ($rows, $landlord, &$counts) {
            foreach ($rows as $row) {
                $property = Property::query()->withoutGlobalScopes()
                    ->firstOrNew(['landlord_id' => $landlord->id, 'name' => $row['property']]);
                if (! $property->exists) {
                    $property->forceFill([
                        'landlord_id' => $landlord->id,
                        'name' => $row['property'],
                        'sub_city' => $row['sub_city'] ?: null,
                        'woreda' => $row['woreda'] ?: null,
                    ])->save();
                    $counts['properties']++;
                }

                $unit = Unit::query()->withoutGlobalScopes()
                    ->firstOrNew(['property_id' => $property->id, 'label' => $row['unit']]);
                if (! $unit->exists) {
                    $unit->forceFill([
                        'landlord_id' => $landlord->id,
                        'property_id' => $property->id,
                        'label' => $row['unit'],
                        'bedrooms' => $row['bedrooms'],
                        'status' => Unit::STATUS_VACANT,
                    ])->save();
                    $counts['units']++;
                }

                if (! $row['tenant_name']) {
                    continue;
                }

                $tenant = Tenant::query()->withoutGlobalScopes()
                    ->firstOrNew(['landlord_id' => $landlord->id, 'phone' => $row['tenant_phone']]);
                if (! $tenant->exists) {
                    $tenant->forceFill([
                        'landlord_id' => $landlord->id,
                        'phone' => $row['tenant_phone'],
                        'full_name' => $row['tenant_name'],
                        'address' => $row['tenant_address'] ?: null,
                    ])->save();
                    $counts['tenants']++;

                    // The landlord tells the tenant about the privacy notice at onboarding.
                    $consent = new Consent([
                        'tenant_id' => $tenant->id, 'kind' => 'privacy_notice',
                        'notice_version' => Consent::PRIVACY_NOTICE_VERSION, 'locale' => $landlord->locale, 'given_at' => now(),
                    ]);
                    $consent->landlord_id = $landlord->id;
                    $consent->save();
                }

                $lease = new Lease([
                    'unit_id' => $unit->id,
                    'tenant_id' => $tenant->id,
                    'start_date' => $row['lease_start'],
                    'end_date' => $row['lease_end'],
                    'monthly_rent' => $row['monthly_rent'],
                    'deposit' => $row['deposit'],
                    'due_day' => $row['due_day'],
                    'condition_notes' => $row['condition_notes'],
                    'status' => Lease::STATUS_ACTIVE,
                ]);
                $lease->landlord_id = $landlord->id;
                $lease->save();
                $unit->forceFill(['status' => Unit::STATUS_OCCUPIED])->save();
                $this->schedule->sync($lease);
                $counts['leases']++;

                if ($row['paid_through']) {
                    $amount = $this->amountThrough($lease, $row['paid_through']);
                    if ($amount > 0) {
                        $this->payments->record($lease, [
                            'amount' => $amount,
                            'paid_at' => min(today()->toDateString(), CarbonImmutable::createFromFormat('!Y-m', $row['paid_through'])->endOfMonth()->toDateString()),
                            'method' => 'cash',
                            'notes' => __('admin.import.opening_payment'),
                        ]);
                        $counts['payments']++;
                    }
                }
            }
        });
    }

    /** @return array{0: array<string, mixed>, 1: list<string>} */
    private function validateRow(array $raw, User $landlord): array
    {
        $errors = [];
        $row = [
            'property' => $raw['property'],
            'sub_city' => $raw['sub_city'],
            'woreda' => $raw['woreda'],
            'unit' => $raw['unit'],
            'bedrooms' => $raw['bedrooms'] === '' ? null : (int) $raw['bedrooms'],
            'tenant_name' => $raw['tenant_name'],
            'tenant_phone' => null,
            'tenant_address' => $raw['tenant_address'],
            'monthly_rent' => null,
            'deposit' => 0,
            'lease_start' => null,
            'lease_end' => null,
            'due_day' => null,
            'condition_notes' => $raw['condition_notes'] ?: __('admin.import.default_condition'),
            'paid_through' => $raw['paid_through'] ?: null,
        ];

        if ($row['property'] === '' || $row['unit'] === '') {
            $errors[] = __('admin.import.property_unit_required');
        }

        $existing = Unit::query()->withoutGlobalScopes()
            ->where('landlord_id', $landlord->id)
            ->where('label', $row['unit'])
            ->whereHas('property', fn ($q) => $q->withoutGlobalScopes()->where('name', $row['property']))
            ->first();
        $hasTenant = $row['tenant_name'] !== '';

        if ($existing && $hasTenant && $existing->activeLease()->withoutGlobalScopes()->exists()) {
            $errors[] = __('admin.import.unit_has_lease');
        }

        if (! $hasTenant) {
            return [$row, $errors];
        }

        $row['tenant_phone'] = PhoneNumber::normalize($raw['tenant_phone']);
        if (! $row['tenant_phone']) {
            $errors[] = __('admin.import.bad_phone');
        }

        $rent = $this->birr($raw['monthly_rent_birr']);
        $rent === null || $rent < 100 ? $errors[] = __('admin.import.bad_rent') : $row['monthly_rent'] = $rent;

        if ($raw['deposit_birr'] !== '') {
            $deposit = $this->birr($raw['deposit_birr']);
            $deposit === null ? $errors[] = __('admin.import.bad_deposit') : $row['deposit'] = $deposit;
        }

        $start = $this->date($raw['lease_start']);
        if (! $start) {
            $errors[] = __('admin.import.bad_start');
        } else {
            $row['lease_start'] = $start->toDateString();
            $minEnd = $start->addYears(RentRules::MIN_LEASE_YEARS)->subDay();
            $end = $raw['lease_end'] === '' ? $minEnd : $this->date($raw['lease_end']);
            if (! $end) {
                $errors[] = __('admin.import.bad_end');
            } elseif ($end->lt($minEnd)) {
                $errors[] = __('leases.min_term', ['years' => RentRules::MIN_LEASE_YEARS, 'date' => $minEnd->toDateString()]);
            } else {
                $row['lease_end'] = $end->toDateString();
            }
            $row['due_day'] = $raw['due_day'] === '' ? min($start->day, 30) : (int) $raw['due_day'];
            if ($row['due_day'] < 1 || $row['due_day'] > 30) {
                $errors[] = __('admin.import.bad_due_day');
            }
        }

        if ($row['paid_through'] && ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $row['paid_through'])) {
            $errors[] = __('admin.import.bad_paid_through');
        }

        return [$row, $errors];
    }

    private function amountThrough(Lease $lease, string $period): int
    {
        $total = 0;
        foreach ($this->schedule->periods($lease) as $p) {
            if ($p > $period) {
                break;
            }
            $total += $this->schedule->rentFor($lease, $p);
        }
        $alreadyPaid = (int) Charge::query()->withoutGlobalScopes()->where('lease_id', $lease->id)
            ->withSum('allocations as paid', 'amount')->get()->sum('paid');

        return max(0, $total - $alreadyPaid);
    }

    private function birr(string $value): ?int
    {
        $clean = str_replace([',', ' ', 'birr', 'Br', 'ብር'], '', $value);

        return preg_match('/^\d+(\.\d{1,2})?$/', $clean) ? (int) round(((float) $clean) * 100) : null;
    }

    /** Gregorian YYYY-MM-DD, or Ethiopian YYYY-MM-DD followed by "EC" or "ዓ.ም". */
    private function date(string $value): ?CarbonImmutable
    {
        $value = trim($value);
        $ethiopian = (bool) preg_match('/\s*(EC|E\.C\.|ዓ\.ም\.?|ዓም)$/iu', $value);
        $value = trim((string) preg_replace('/\s*(EC|E\.C\.|ዓ\.ም\.?|ዓም)$/iu', '', $value));

        if (! preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m)) {
            return null;
        }
        [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];

        if ($ethiopian) {
            if ($mo < 1 || $mo > 13 || $d < 1 || $d > EthiopianDate::daysInMonth($y, $mo)) {
                return null;
            }

            return EthiopianDate::toGregorian($y, $mo, $d);
        }

        return checkdate($mo, $d, $y) ? CarbonImmutable::create($y, $mo, $d) : null;
    }
}
