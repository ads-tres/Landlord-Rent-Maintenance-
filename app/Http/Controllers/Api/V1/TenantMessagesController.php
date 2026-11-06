<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SmsMessage;
use App\Models\TelegramLink;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/** GET /tenants/{id}/messages — every SMS and Telegram message sent to the tenant, newest first. */
class TenantMessagesController extends Controller
{
    public function __invoke(Tenant $tenant): JsonResponse
    {
        Gate::authorize('view', $tenant);

        $chats = TelegramLink::query()
            ->where('linkable_type', $tenant->getMorphClass())
            ->where('linkable_id', $tenant->id)
            ->pluck('chat_id')
            ->map(fn ($id) => (string) $id);

        $messages = SmsMessage::query()
            ->where('landlord_id', $tenant->landlord_id)
            ->where(fn ($q) => $q
                ->where(fn ($s) => $s->where('channel', '!=', SmsMessage::CHANNEL_TELEGRAM)->where('to', $tenant->phone))
                ->orWhere(fn ($t) => $t->where('channel', SmsMessage::CHANNEL_TELEGRAM)->whereIn('to', $chats)))
            ->latest('id')
            ->limit(100)
            ->get();

        return response()->json(['data' => $messages->map(fn (SmsMessage $m) => [
            'id' => $m->id,
            'kind' => $m->kind,
            'channel' => $m->channel ?: 'sms',
            'status' => $m->status,
            'body' => $m->body,
            'segments' => $m->segments,
            'error' => $m->status === 'failed' ? $m->error : null,
            'created_at' => $m->created_at->toIso8601String(),
            'sent_at' => $m->sent_at?->toIso8601String(),
            'send_after' => $m->status === 'queued' ? $m->send_after?->toIso8601String() : null,
        ])]);
    }
}
