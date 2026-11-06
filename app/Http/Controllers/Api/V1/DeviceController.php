<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Phones registered for push notifications. */
class DeviceController extends Controller
{
    /** POST /devices {token, platform?} — a token moves to whoever logs in on that phone. */
    public function store(Request $request): Response
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['sometimes', 'in:android,ios'],
        ]);

        DeviceToken::query()->updateOrCreate(
            ['token' => $data['token']],
            ['user_id' => $request->user()->id, 'platform' => $data['platform'] ?? 'android', 'last_seen_at' => now()],
        );

        return response()->noContent();
    }

    /** DELETE /devices {token} — on logout, so the phone stops getting this account's alerts. */
    public function destroy(Request $request): Response
    {
        $token = $request->validate(['token' => ['required', 'string', 'max:512']])['token'];
        DeviceToken::query()->where('user_id', $request->user()->id)->where('token', $token)->delete();

        return response()->noContent();
    }
}
