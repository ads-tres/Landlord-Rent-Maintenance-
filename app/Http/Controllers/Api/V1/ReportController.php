<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Payments\ReceiptData;
use App\Services\Reports\AnnualReport;
use App\Services\Reports\AnnualWorkbook;
use App\Services\Reports\QuarterlyReport;
use App\Services\Reports\QuarterlyWorkbook;
use App\Support\DateDisplay;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class ReportController extends Controller
{
    /**
     * GET /reports/quarterly?year=&quarter=&calendar=ethiopian|gregorian&format=pdf|xlsx
     * Without year/quarter: the current quarter. Calendar defaults to the landlord's preference.
     */
    public function quarterly(Request $request, QuarterlyReport $report): Response
    {
        $data = $request->validate([
            'year' => ['sometimes', 'integer', 'between:1990,2100'],
            'quarter' => ['sometimes', 'integer', 'between:1,4', 'required_with:year'],
            'calendar' => ['sometimes', Rule::in([DateDisplay::ETHIOPIAN, DateDisplay::GREGORIAN])],
            'format' => ['sometimes', Rule::in(['json', 'pdf', 'xlsx'])],
        ]);

        $user = $request->user();
        $calendar = $data['calendar'] ?? ($user->calendar_pref ?: DateDisplay::GREGORIAN);
        $current = QuarterlyReport::quarterOf(CarbonImmutable::today(), $calendar);
        $locale = app()->getLocale();

        if (($data['format'] ?? 'json') === 'xlsx') {
            $year = $data['year'] ?? $current['year'];
            $quarter = $data['quarter'] ?? $current['quarter'];
            $path = app(QuarterlyWorkbook::class)->write($user, $year, $quarter, $calendar, $locale);

            return response()->download($path, sprintf('rent-and-expenses-%d-Q%d.xlsx', $year, $quarter), [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend();
        }

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

    /** GET /reports/annual?year=&calendar=&format=json|pdf|xlsx — without year: the current one. */
    public function annual(Request $request, AnnualReport $report): Response
    {
        $data = $request->validate([
            'year' => ['sometimes', 'integer', 'between:1990,2100'],
            'calendar' => ['sometimes', Rule::in([DateDisplay::ETHIOPIAN, DateDisplay::GREGORIAN])],
            'format' => ['sometimes', Rule::in(['json', 'pdf', 'xlsx'])],
        ]);
        $user = $request->user();
        $calendar = $data['calendar'] ?? ($user->calendar_pref ?: DateDisplay::GREGORIAN);
        $year = $data['year'] ?? AnnualReport::yearOf(CarbonImmutable::today(), $calendar);
        $locale = app()->getLocale();

        if (($data['format'] ?? 'json') === 'xlsx') {
            $path = app(AnnualWorkbook::class)->write($user, $year, $calendar, $locale);

            return response()->download($path, sprintf('annual-summary-%d.xlsx', $year), [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend();
        }

        $result = $report->build($year, $calendar, $locale);
        if (($data['format'] ?? 'json') === 'pdf') {
            return Pdf::loadView('reports.annual-pdf', [
                'r' => $result,
                'locale' => $locale,
                'landlord' => $user->name ?: $user->phone,
                'birr' => fn (int $s) => ReceiptData::birr($s, $locale),
            ])->setPaper('a4')->download(sprintf('annual-summary-%d.pdf', $year));
        }

        return response()->json(['data' => $result]);
    }
}
