<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lease;
use App\Services\Leases\LeaseStatement;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class LeaseStatementController extends Controller
{
    /** GET /leases/{id}/statement?from=&to=&language=am|en&format=json|pdf — defaults to the whole lease up to today. */
    public function __invoke(Request $request, Lease $lease): Response
    {
        Gate::authorize('view', $lease);
        $data = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'language' => ['sometimes', Rule::in(['am', 'en'])],
            'format' => ['sometimes', Rule::in(['json', 'pdf'])],
        ]);

        $from = CarbonImmutable::parse($data['from'] ?? $lease->start_date);
        $to = CarbonImmutable::parse($data['to'] ?? min(today(), $lease->terminated_on ?? $lease->end_date));
        $locale = $data['language'] ?? app()->getLocale();
        $statement = LeaseStatement::build($lease, $from, $to, $locale);

        if (($data['format'] ?? 'json') === 'pdf') {
            return Pdf::loadView('statements.lease', ['s' => $statement])
                ->setPaper('a4')
                ->download(sprintf('statement-%d-%s-%s.pdf', $lease->id, $from->toDateString(), $to->toDateString()));
        }

        return response()->json(['data' => $statement]);
    }
}
