<?php

namespace App\Services\Reports;

use App\Models\Charge;
use App\Models\Expense;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\User;
use App\Support\DateDisplay;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * The quarter as an Excel workbook for an accountant or the tax office: a summary, every
 * payment, every expense and a rent roll per lease. Dates in both calendars; amounts in birr.
 * Like the PDF, it reports what was recorded and does not calculate tax.
 */
class QuarterlyWorkbook
{
    private const MONEY = '#,##0.00';

    public function __construct(private readonly QuarterlyReport $report) {}

    /** Writes the workbook to a temporary file and returns its path. */
    public function write(User $landlord, int $year, int $quarter, string $calendar, string $locale): string
    {
        $r = $this->report->build($year, $quarter, $calendar, $locale);
        [$from, $to] = QuarterlyReport::range($year, $quarter, $calendar);
        $t = fn (string $key, array $replace = []) => __('workbook.'.$key, $replace, $locale);
        $birr = fn (int $santim) => round($santim / 100, 2);
        $dates = fn ($d) => [DateDisplay::date($d, $locale, DateDisplay::GREGORIAN), DateDisplay::date($d, $locale, DateDisplay::ETHIOPIAN)];

        $bold = (new Style)->setFontBold();
        $header = (new Style)->setFontBold()->setBackgroundColor('E7F4EE');
        $money = (new Style)->setFormat(self::MONEY);
        $moneyBold = (new Style)->setFontBold()->setFormat(self::MONEY);

        $path = tempnam(sys_get_temp_dir(), 'wb').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);

        // 1. Summary
        $sheet = $writer->getCurrentSheet();
        $sheet->setName($t('sheets.summary'));
        $sheet->setColumnWidth(36, 1);
        $sheet->setColumnWidth(18, 2, 3, 4);
        $writer->addRow(Row::fromValues([config('app.name').' · '.$t('title').' · '.$r['label']], $bold));
        $writer->addRow(Row::fromValues([$t('period'), $r['from_display'].' – '.$r['to_display']]));
        $writer->addRow(Row::fromValues([$t('landlord'), $landlord->name ?: $landlord->phone]));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValuesWithStyles([$t('income'), $birr($r['total'])], null, [1 => $moneyBold]));
        $writer->addRow(Row::fromValuesWithStyles([$t('expenses'), $birr($r['expenses_total'])], null, [1 => $moneyBold]));
        $writer->addRow(Row::fromValuesWithStyles([$t('net'), $birr($r['net'])], null, [1 => $moneyBold]));
        $writer->addRow(Row::fromValues([]));

        $writer->addRow(Row::fromValues([$t('by_property'), $t('income'), $t('expenses'), $t('net')], $header));
        foreach ($r['by_property'] as $p) {
            $writer->addRow(Row::fromValuesWithStyles([$p['property'], $birr($p['total']), $birr($p['expenses']), $birr($p['total'] - $p['expenses'])], null, [1 => $money, 2 => $money, 3 => $money]));
        }
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues([$t('by_month'), $t('income')], $header));
        foreach ($r['by_month'] as $m) {
            $writer->addRow(Row::fromValuesWithStyles([$m['label'], $birr($m['total'])], null, [1 => $money]));
        }
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues([$t('by_category'), $t('expenses')], $header));
        foreach ($r['expenses_by_category'] as $c) {
            $writer->addRow(Row::fromValuesWithStyles([$c['label'], $birr($c['total'])], null, [1 => $money]));
        }
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues([__('reports.disclaimer', [], $locale)]));
        $writer->addRow(Row::fromValues([__('reports.expenses_note', [], $locale)]));

        // 2. Payments
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName($t('sheets.payments'));
        $sheet->setColumnWidth(16, 1, 2, 3);
        $sheet->setColumnWidth(24, 4, 5);
        $writer->addRow(Row::fromValues([$t('date'), $t('date_ethiopian'), $t('receipt'), $t('tenant'), $t('property'), $t('unit'), $t('method'), $t('reference'), $t('amount'), $t('recorded_by')], $header));
        $payments = Payment::query()
            ->with(['lease.unit.property', 'tenant', 'recordedBy'])
            ->whereBetween('paid_at', [$from->toDateString(), $to->toDateString()])
            ->orderBy('paid_at')->orderBy('id')
            ->get();
        foreach ($payments as $p) {
            $writer->addRow(Row::fromValuesWithStyles([
                ...$dates($p->paid_at),
                $p->receiptNumber(),
                $p->tenant?->full_name,
                $p->lease?->unit?->property?->name,
                $p->lease?->unit?->label,
                __('payments.methods.'.$p->method, [], $locale),
                $p->reference,
                $birr($p->amount),
                $p->recorded_by && $p->recorded_by !== $p->landlord_id ? $p->recordedBy?->name : null,
            ], null, [8 => $money]));
        }
        $writer->addRow(Row::fromValuesWithStyles([$t('total'), '', '', '', '', '', '', '', $birr((int) $payments->sum('amount'))], $bold, [8 => $moneyBold]));

        // 3. Expenses
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName($t('sheets.expenses'));
        $sheet->setColumnWidth(16, 1, 2);
        $sheet->setColumnWidth(28, 6);
        $writer->addRow(Row::fromValues([$t('date'), $t('date_ethiopian'), $t('property'), $t('unit'), $t('category'), $t('description'), $t('vendor'), $t('amount'), $t('source')], $header));
        $expenses = Expense::query()
            ->with(['property:id,name', 'unit:id,label'])
            ->whereBetween('spent_on', [$from->toDateString(), $to->toDateString()])
            ->orderBy('spent_on')->orderBy('id')
            ->get();
        foreach ($expenses as $e) {
            $writer->addRow(Row::fromValuesWithStyles([
                ...$dates($e->spent_on),
                $e->property?->name,
                $e->unit?->label,
                __('expenses.categories.'.$e->category, [], $locale),
                $e->description,
                $e->vendor,
                $birr($e->amount),
                $e->isAutomatic() ? $t('automatic') : $t('manual'),
            ], null, [7 => $money]));
        }
        $writer->addRow(Row::fromValuesWithStyles([$t('total'), '', '', '', '', '', '', $birr((int) $expenses->sum('amount'))], $bold, [7 => $moneyBold]));

        // 4. Rent roll: each lease that overlapped the quarter.
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName($t('sheets.rent_roll'));
        $sheet->setColumnWidth(24, 1, 2);
        $writer->addRow(Row::fromValues([$t('tenant'), $t('property'), $t('unit'), $t('lease_start'), $t('lease_end'), $t('monthly_rent'), $t('charged'), $t('received'), $t('outstanding')], $header));
        $leases = Lease::query()
            ->with(['unit.property', 'tenant'])
            ->whereIn('status', [Lease::STATUS_ACTIVE, Lease::STATUS_ENDED])
            ->where('start_date', '<=', $to->toDateString())
            ->whereRaw('COALESCE(terminated_on, end_date) >= ?', [$from->toDateString()])
            ->get()
            ->sortBy(fn (Lease $l) => [$l->unit?->property?->name, $l->unit?->label]);
        $paidByLease = $payments->groupBy('lease_id')->map->sum('amount');
        foreach ($leases as $l) {
            $charged = (int) Charge::query()->where('lease_id', $l->id)
                ->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])
                ->sum('amount_due');
            $outstanding = (int) Charge::query()->where('lease_id', $l->id)
                ->where('due_date', '<=', $to->toDateString())
                ->withSum('allocations as paid', 'amount')
                ->get()
                ->sum(fn (Charge $c) => max(0, $c->amount_due - (int) $c->paid));
            $writer->addRow(Row::fromValuesWithStyles([
                $l->tenant?->full_name,
                $l->unit?->property?->name,
                $l->unit?->label,
                DateDisplay::date($l->start_date, $locale, $calendar),
                DateDisplay::date($l->terminated_on ?? $l->end_date, $locale, $calendar),
                $birr($l->monthly_rent),
                $birr($charged),
                $birr((int) ($paidByLease[$l->id] ?? 0)),
                $birr($outstanding),
            ], null, [5 => $money, 6 => $money, 7 => $money, 8 => $money]));
        }

        $writer->close();

        return $path;
    }
}
