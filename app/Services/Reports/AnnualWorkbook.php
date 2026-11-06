<?php

namespace App\Services\Reports;

use App\Models\User;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/** The annual summary as a one-sheet workbook: totals, then month by month, then by property. */
class AnnualWorkbook
{
    public function __construct(private readonly AnnualReport $report) {}

    public function write(User $landlord, int $year, string $calendar, string $locale): string
    {
        $r = $this->report->build($year, $calendar, $locale);
        $t = fn (string $key) => __('reports.'.$key, [], $locale);
        $birr = fn (int $s) => round($s / 100, 2);
        $money = (new Style)->setFormat('#,##0.00');
        $header = (new Style)->setFontBold()->setBackgroundColor('E7F4EE');
        $bold = (new Style)->setFontBold();

        $path = tempnam(sys_get_temp_dir(), 'yr').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $sheet = $writer->getCurrentSheet();
        $sheet->setName(mb_substr($r['label'], 0, 31));
        $sheet->setColumnWidth(24, 1);
        $sheet->setColumnWidth(16, 2, 3, 4, 5, 6);

        $writer->addRow(Row::fromValues([config('app.name').' · '.$t('annual_title').' · '.$r['label']], $bold));
        $writer->addRow(Row::fromValues([$r['from_display'].' – '.$r['to_display'], $landlord->name ?: $landlord->phone]));
        $writer->addRow(Row::fromValues([]));
        foreach ([['total', $r['income']], ['refunded', $r['refunded']], ['expenses_total', $r['expenses']], ['net', $r['net']]] as [$key, $value]) {
            $writer->addRow(Row::fromValuesWithStyles([$t($key), $birr($value)], null, [1 => $money]));
        }
        $writer->addRow(Row::fromValues([$t('average_occupancy'), $r['average_occupancy'].'%']));
        $writer->addRow(Row::fromValues([]));

        $writer->addRow(Row::fromValues([$t('by_month'), $t('col_income'), $t('col_refunds'), $t('col_expenses'), $t('col_net'), $t('col_occupancy')], $header));
        foreach ($r['months'] as $m) {
            $writer->addRow(Row::fromValuesWithStyles(
                [$m['label'], $birr($m['income']), $birr($m['refunded']), $birr($m['expenses']), $birr($m['net']), $m['occupancy'] / 100],
                null,
                [1 => $money, 2 => $money, 3 => $money, 4 => $money, 5 => (new Style)->setFormat('0%')],
            ));
        }
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues([$t('by_property'), $t('col_income'), $t('col_refunds'), $t('col_expenses'), $t('col_net')], $header));
        foreach ($r['by_property'] as $p) {
            $writer->addRow(Row::fromValuesWithStyles([$p['property'], $birr($p['income']), $birr($p['refunded']), $birr($p['expenses']), $birr($p['net'])], null, [1 => $money, 2 => $money, 3 => $money, 4 => $money]));
        }
        $writer->close();

        return $path;
    }
}
