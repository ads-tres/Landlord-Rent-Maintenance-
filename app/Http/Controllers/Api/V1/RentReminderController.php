<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Rent\BulkReminder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RentReminderController extends Controller
{
    public function __construct(private BulkReminder $reminders) {}

    /** GET /rent/reminders?period=YYYY-MM — who would be texted, who is skipped, and a sample message. */
    public function preview(Request $request): JsonResponse
    {
        $period = $this->period($request);
        $landlord = $request->user()->actingLandlord();
        $candidates = $this->reminders->candidates($period);
        $first = $candidates->firstWhere('skip', null);

        return response()->json(['data' => [
            'period' => $period,
            'will_send' => $candidates->whereNull('skip')->count(),
            'skipped' => $candidates->whereNotNull('skip')->count(),
            'total_owed' => (int) $candidates->sum('owed'),
            'sample' => $first ? $this->reminders->message($first['lease'], $first['owed'], $period, $first['due_date'], $landlord) : null,
            'items' => $candidates->map(fn ($c) => [
                'lease_id' => $c['lease']->id,
                'tenant' => $c['lease']->tenant?->full_name,
                'phone' => $c['lease']->tenant?->phone,
                'unit' => $c['lease']->unit?->label,
                'property' => $c['lease']->unit?->property?->name,
                'owed' => $c['owed'],
                'due_date' => $c['due_date'],
                'skip' => $c['skip'],
            ]),
        ]]);
    }

    /** POST /rent/reminders {period?, lease_ids?} — texts them now (quiet hours still apply). */
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lease_ids' => ['sometimes', 'array', 'max:500'],
            'lease_ids.*' => ['integer'],
        ]);

        $result = $this->reminders->send(
            $this->period($request),
            $request->user()->actingLandlord(),
            isset($data['lease_ids']) ? array_map('intval', $data['lease_ids']) : null,
        );

        return response()->json(['data' => $result]);
    }

    private function period(Request $request): string
    {
        return $request->validate(['period' => ['sometimes', 'date_format:Y-m']])['period'] ?? now()->format('Y-m');
    }
}
