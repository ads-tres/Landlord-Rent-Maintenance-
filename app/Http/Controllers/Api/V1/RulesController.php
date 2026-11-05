<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Rent\RentRules;
use Illuminate\Http\JsonResponse;

/** GET /rules — legal values the app checks before submitting (they change yearly). */
class RulesController extends Controller
{
    public function __invoke(RentRules $rules): JsonResponse
    {
        return response()->json([
            'data' => [
                'rent_cap_percent' => $rules->capPercent(),
                'min_lease_years' => RentRules::MIN_LEASE_YEARS,
            ],
        ]);
    }
}
