<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Notes that a landlord used the app today (one row per day), for the pilot's
 * "weekly active landlords" measure. Cached so it costs one query per landlord per day.
 */
class RecordLandlordActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();
        // A caretaker's work counts as their landlord using the app.
        if ($user instanceof User && in_array($user->role, [User::ROLE_LANDLORD, User::ROLE_CARETAKER], true)) {
            $day = now()->toDateString();
            $landlordId = $user->landlordId();
            Cache::remember("activity:{$landlordId}:{$day}", now()->endOfDay(), function () use ($landlordId, $day) {
                DB::table('landlord_activity_days')->insertOrIgnore(['landlord_id' => $landlordId, 'day' => $day]);

                return true;
            });
        }

        return $response;
    }
}
