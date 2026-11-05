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
        if ($user instanceof User && $user->role === User::ROLE_LANDLORD) {
            $day = now()->toDateString();
            Cache::remember("activity:{$user->id}:{$day}", now()->endOfDay(), function () use ($user, $day) {
                DB::table('landlord_activity_days')->insertOrIgnore(['landlord_id' => $user->id, 'day' => $day]);

                return true;
            });
        }

        return $response;
    }
}
