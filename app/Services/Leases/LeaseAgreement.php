<?php

namespace App\Services\Leases;

use App\Models\Lease;
use App\Models\MeterUnit;
use App\Services\Payments\ReceiptData;
use App\Services\Rent\RentRules;
use App\Support\DateDisplay;
use Carbon\CarbonImmutable;

/**
 * The written lease, built from what the app knows about the lease. Anything missing is left
 * as a blank line to fill in by hand. It is a plain-language template, not legal advice.
 */
class LeaseAgreement
{
    public const BLANK = '________________';

    public const LANGUAGES = ['am', 'en', 'both'];

    /** Details that will print as blank lines, so the landlord can fill them in first. */
    public static function blanks(Lease $lease): array
    {
        $property = $lease->unit?->property;

        return array_values(array_filter([
            blank($lease->landlord?->name) ? 'landlord_name' : null,
            blank($lease->landlord?->id_number) ? 'landlord_id_number' : null,
            blank($lease->tenant?->address) ? 'tenant_address' : null,
            blank($lease->tenant?->id_number) ? 'tenant_id_number' : null,
            $property && blank($property->sub_city) && blank($property->woreda) && blank($property->address) ? 'property_location' : null,
            blank($lease->condition_notes) ? 'condition_notes' : null,
        ]));
    }

    /**
     * @return array{locale: string, title: string, clauses: list<array{title: string, text: string}>, labels: array<string, string>, checklist: list<string>, checklist_title: string, disclaimer: string}
     */
    public static function build(Lease $lease, string $locale): array
    {
        $lease->loadMissing(['unit.property', 'tenant', 'landlord']);
        $t = fn (string $key, array $replace = []) => __('agreement.'.$key, $replace, $locale);
        $orBlank = fn ($value) => filled($value) ? (string) $value : self::BLANK;

        $landlord = $lease->landlord;
        $tenant = $lease->tenant;
        $unit = $lease->unit;
        $property = $unit->property;
        $calendar = $landlord?->calendar_pref ?: DateDisplay::GREGORIAN;
        $other = $calendar === DateDisplay::ETHIOPIAN ? DateDisplay::GREGORIAN : DateDisplay::ETHIOPIAN;
        // Dates in the landlord's calendar, with the other calendar alongside.
        $date = fn ($d) => DateDisplay::date($d, $locale, $calendar).' ('.DateDisplay::date($d, $locale, $other).')';
        $birr = fn (int $santim) => ReceiptData::birr($santim, $locale);

        $location = collect([
            $property->sub_city,
            filled($property->woreda) ? __('listings.woreda', ['w' => $property->woreda], $locale) : null,
        ])->filter()->implode(', ');

        $start = CarbonImmutable::parse($lease->start_date);
        $endExclusive = CarbonImmutable::parse($lease->end_date)->addDay();
        $months = (int) round($start->diffInMonths($endExclusive, true));
        $length = $months % 12 === 0
            ? trans_choice('agreement.years', intdiv($months, 12), ['count' => intdiv($months, 12)], $locale)
            : trans_choice('agreement.months', $months, ['count' => $months], $locale);

        $clauses = [
            ['parties', [
                'landlord' => $orBlank($landlord?->name),
                'landlord_phone' => $orBlank($landlord?->phone),
                'landlord_id' => $orBlank($landlord?->id_number),
                'landlord_address' => $orBlank($landlord?->address),
                'tenant' => $orBlank($tenant->full_name),
                'tenant_phone' => $orBlank($tenant->phone),
                'tenant_id' => $orBlank($tenant->id_number),
                'tenant_address' => $orBlank($tenant->address),
            ]],
            ['home', [
                'unit' => $unit->label,
                'bedrooms' => $unit->bedrooms ? ' '.$t('bedrooms', ['count' => $unit->bedrooms]) : '',
                'property' => $property->name,
                'location' => $orBlank($location),
                'address' => $orBlank($property->address),
            ]],
            ['term', ['start' => $date($lease->start_date), 'end' => $date($lease->end_date), 'length' => $length]],
            ['rent', [
                'rent' => $birr($lease->monthly_rent),
                'due' => $t('due_day.'.($lease->due_calendar === DateDisplay::ETHIOPIAN ? 'ethiopian' : 'gregorian'), ['day' => $lease->due_day]),
                'online' => $landlord?->acceptsOnlineRent() ? $t('online') : '',
            ]],
            $lease->deposit > 0 ? ['deposit', ['deposit' => $birr($lease->deposit)]] : ['no_deposit', []],
            self::utilities($lease, $locale),
            ['rent_changes', ['cap' => rtrim(rtrim(number_format(app(RentRules::class)->capPercent(), 2), '0'), '.')]],
            ['use', []],
            ['repairs', []],
            ['condition', ['condition' => filled($lease->condition_notes) ? $lease->condition_notes : self::BLANK."\n".self::BLANK]],
            ['ending', []],
            ['registration', []],
        ];
        if (filled($lease->extra_terms)) {
            $clauses[] = ['extra_terms', ['terms' => $lease->extra_terms]];
        }

        return [
            'locale' => $locale,
            'title' => $t('title'),
            'clauses' => array_map(fn ($c) => [
                'title' => $t('clauses.'.$c[0].'.title'),
                'text' => $t('clauses.'.$c[0].'.text', $c[1]),
            ], $clauses),
            'labels' => [
                'signatures' => $t('signatures'),
                'signed_on' => $t('signed_on', ['date' => self::BLANK]),
                'landlord' => $t('landlord'),
                'tenant' => $t('tenant'),
                'witness_1' => $t('witness', ['n' => 1]),
                'witness_2' => $t('witness', ['n' => 2]),
                'name' => $t('name'),
                'signature' => $t('signature'),
            ],
            'checklist_title' => $t('checklist.title'),
            'checklist' => (array) __('agreement.checklist.items', [], $locale),
            'disclaimer' => $t('disclaimer', ['app' => config('app.name')]),
        ];
    }

    /** The utilities clause: shared meters and how each is split, or the tenant pays their own. */
    private static function utilities(Lease $lease, string $locale): array
    {
        $shares = MeterUnit::query()->withoutGlobalScopes()
            ->where('unit_id', $lease->unit_id)
            ->with(['meter' => fn ($q) => $q->withoutGlobalScopes()])
            ->get()
            ->filter(fn (MeterUnit $mu) => $mu->meter !== null);

        if ($shares->isEmpty()) {
            return ['own_utilities', []];
        }

        $meters = $shares->map(fn (MeterUnit $mu) => __('agreement.meter', [
            'name' => $mu->meter->name,
            'kind' => __('agreement.kinds.'.$mu->meter->kind, [], $locale),
            'method' => __('agreement.split.'.$mu->meter->split_method, [
                'percent' => rtrim(rtrim(number_format((float) $mu->share_percent, 2), '0'), '.'),
            ], $locale),
        ], $locale))->implode('; ');

        return ['shared_utilities', ['meters' => $meters]];
    }
}
