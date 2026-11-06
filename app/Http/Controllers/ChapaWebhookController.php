<?php

namespace App\Http\Controllers;

use App\Services\Chapa\ChapaClient;
use App\Services\Chapa\OnlineRent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/chapa/webhook — checks Chapa's signature, then asks Chapa for the final state.
 * The payload's own status is never trusted.
 */
class ChapaWebhookController extends Controller
{
    public function __invoke(Request $request, ChapaClient $chapa, OnlineRent $online): JsonResponse
    {
        $signature = $request->header('x-chapa-signature') ?? $request->header('chapa-signature');
        abort_unless($chapa->validSignature($request->getContent(), $signature), 403);

        $txRef = (string) ($request->input('tx_ref') ?? $request->input('trx_ref') ?? '');
        if ($txRef !== '') {
            $online->confirm($txRef);
        }

        return response()->json(['ok' => true]);
    }
}
