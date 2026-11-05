<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TelegramLink;
use App\Services\Telegram\TelegramClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** The landlord connects their own Telegram for repair alerts. */
class TelegramLinkController extends Controller
{
    /** POST /telegram/link — a one-time code and the t.me link that opens the bot with it. */
    public function store(Request $request, TelegramClient $telegram): JsonResponse
    {
        abort_unless($telegram->enabled() && filled(config('telegram.username')), 503, __('api.telegram_off'));

        $code = Str::lower(Str::random(12));
        DB::table('telegram_link_codes')->where('user_id', $request->user()->id)->delete();
        DB::table('telegram_link_codes')->insert([
            'code' => $code,
            'user_id' => $request->user()->id,
            'expires_at' => now()->addMinutes(15),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['data' => [
            'url' => 'https://t.me/'.config('telegram.username').'?start='.$code,
            'expires_in' => 900,
        ]], 201);
    }

    /** DELETE /telegram/link — stop Telegram alerts. */
    public function destroy(Request $request): Response
    {
        TelegramLink::query()
            ->where('linkable_type', $request->user()->getMorphClass())
            ->where('linkable_id', $request->user()->id)
            ->delete();

        return response()->noContent();
    }
}
