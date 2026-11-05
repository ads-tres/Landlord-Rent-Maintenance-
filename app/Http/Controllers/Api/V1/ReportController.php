<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Payments\ReceiptData;
use App\Services\Reports\QuarterlyReport;
use App\Support\DateDisplay;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class ReportController extends Controller
{
    /**
     * GET /reports/quarterly?year=&quarter=&calendar=ethiopian|gregorian&format=pdf
     * Without year/quarter: the current quarter. Calendar defaults to the landlord's preference.
     */
    public function quarterly(Request $request, QuarterlyReport $report): Response
    {
        $data = $request->validate([
            'year' => ['sometimes', 'integer', 'between:1990,2100'],
            'quarter' => ['sometimes', 'integer', 'between:1,4', 'required_with:year'],
            'calendar' => ['sometimes', Rule::in([DateDisplay::ETHIOPIAN, DateDisplay::GREGORIAN])],
            'format' => ['sometimes', Rule::in(['json', 'pdf'])],
        ]);

        $user = $request->user();
        $calendar = $data['calendar'] ?? ($user->calendar_pref ?: DateDisplay::GREGORIAN);
        $current = QuarterlyReport::quarterOf(CarbonImmutable::today(), $calendar);
        $locale = app()->getLocale();

        $result = $report->build($data['year'] ?? $current['year'], $data['quarter'] ?? $current['quarter'], $calendar, $locale);

        if (($data['format'] ?? 'json') === 'pdf') {
            $filename = sprintf('rent-income-%d-Q%d.pdf', $result['year'], $result['quarter']);

            return Pdf::loadView('reports.quarterly-pdf', [
                'r' => $result,
                'locale' => $locale,
                'landlord' => $user->name ?: $user->phone,
                'birr' => fn (int $s) => ReceiptData::birr($s, $locale),
            ])->setPaper('a4')->download($filename);
        }

        return response()->json(['data' => $result]);
    }
}
