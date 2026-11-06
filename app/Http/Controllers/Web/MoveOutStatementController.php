<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\MoveOut;
use App\Services\MoveOut\MoveOutStatement;
use Illuminate\View\View;

/** Tenant's deposit statement, linked from the move-out SMS: /mo/{token}. No login. */
class MoveOutStatementController extends Controller
{
    public function show(string $token): View
    {
        $moveOut = MoveOut::query()->withoutGlobalScopes()
            ->where('statement_token', $token)
            ->where('status', MoveOut::STATUS_SETTLED)
            ->firstOrFail();

        return view('move-outs.show', ['s' => MoveOutStatement::for($moveOut, app()->getLocale())]);
    }
}
