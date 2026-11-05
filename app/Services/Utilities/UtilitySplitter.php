<?php

namespace App\Services\Utilities;

use App\Models\Lease;
use App\Models\Meter;
use App\Models\MeterUnit;
use Illuminate\Validation\ValidationException;

/**
 * Splits a shared meter's bill across its units. Amounts in santim.
 *
 * - equal:    the same share for every unit on the meter
 * - percent:  the fixed percentages set on the meter (they add up to 100)
 * - submeter: in proportion to each unit's sub-meter reading (current − previous)
 *
 * Every unit gets its share, so the parts always add up to the bill exactly (largest-remainder
 * rounding). A vacant unit's share is the landlord's, not spread onto the tenants.
 */
class UtilitySplitter
{
    /**
     * @param  array<int, array{previous: float, current: float}>  $readings  by unit id (submeter only)
     * @return array{lines: list<array{unit_id: int, unit_label: string, lease: ?Lease, weight: float, amount: int}>, landlord_share: int}
     */
    public function split(Meter $meter, int $amount, array $readings = []): array
    {
        $meterUnits = $meter->meterUnits()->with(['unit.activeLease.tenant'])->get();
        if ($meterUnits->isEmpty()) {
            throw ValidationException::withMessages(['meter' => __('utilities.no_units')]);
        }

        $weights = $meterUnits->mapWithKeys(fn (MeterUnit $mu) => [$mu->unit_id => match ($meter->split_method) {
            'equal' => 1.0,
            'percent' => (float) $mu->share_percent,
            'submeter' => $this->usage($mu->unit_id, $readings),
        }])->all();

        $total = array_sum($weights);
        if ($total <= 0) {
            throw ValidationException::withMessages(['readings' => __('utilities.no_usage')]);
        }

        // Largest remainder: floor every share, then give the leftover santim to the biggest fractions.
        $exact = array_map(fn ($w) => $amount * $w / $total, $weights);
        $shares = array_map(fn ($x) => (int) floor($x), $exact);
        $leftover = $amount - array_sum($shares);
        $fractions = array_map(fn ($x, $s) => $x - $s, $exact, $shares);
        $order = array_keys($fractions);
        usort($order, fn ($a, $b) => $fractions[$b] <=> $fractions[$a] ?: $a <=> $b);
        $keys = array_keys($shares);
        for ($i = 0; $i < $leftover; $i++) {
            $shares[$keys[$order[$i]]]++;
        }

        $lines = [];
        $landlordShare = 0;
        foreach ($meterUnits as $mu) {
            $lease = $mu->unit->activeLease;
            $share = $shares[$mu->unit_id];
            if (! $lease) {
                $landlordShare += $share;
            }
            $lines[] = [
                'unit_id' => $mu->unit_id,
                'unit_label' => $mu->unit->label,
                'lease' => $lease,
                'weight' => $weights[$mu->unit_id],
                'amount' => $share,
            ];
        }

        return ['lines' => $lines, 'landlord_share' => $landlordShare];
    }

    private function usage(int $unitId, array $readings): float
    {
        $r = $readings[$unitId] ?? null;
        if (! $r) {
            throw ValidationException::withMessages(['readings' => __('utilities.reading_missing')]);
        }
        if ($r['current'] < $r['previous']) {
            throw ValidationException::withMessages(['readings' => __('utilities.reading_backwards')]);
        }

        return (float) $r['current'] - (float) $r['previous'];
    }
}
