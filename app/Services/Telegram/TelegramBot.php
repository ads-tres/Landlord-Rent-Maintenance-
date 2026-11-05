<?php

namespace App\Services\Telegram;

use App\Models\Lease;
use App\Models\TelegramChat;
use App\Models\TelegramLink;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Repairs\PhotoStore;
use App\Services\Repairs\RepairService;
use App\Support\PhoneNumber;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Handles one Telegram update.
 *
 * Landlords:  /start <code>  (code from the app) links their chat; they get new-repair alerts.
 * Tenants:    /start → share own contact → we match tenant records by phone → consent →
 *             receipts, reminders and repair updates come here instead of SMS.
 *             /repair → text, photo or voice note → a repair for their landlord.
 *             /stop → back to SMS.
 *
 * The bot runs without a signed-in user, so every query skips the landlord scope and
 * checks ownership through the chat's links instead.
 */
class TelegramBot
{
    public const STATE_AWAITING_REPAIR = 'awaiting_repair';

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly RepairService $repairs,
        private readonly PhotoStore $photos,
    ) {}

    /** @param  array<string, mixed>  $update */
    public function handle(array $update): void
    {
        try {
            if (isset($update['callback_query'])) {
                $this->onCallback($update['callback_query']);
            } elseif (isset($update['message']['chat']['id'])) {
                $this->onMessage($update['message']);
            }
        } catch (Throwable $e) {
            Log::error('Telegram update failed', ['error' => $e->getMessage(), 'update_id' => $update['update_id'] ?? null]);
        }
    }

    /** @param  array<string, mixed>  $message */
    private function onMessage(array $message): void
    {
        $chatId = (int) $message['chat']['id'];
        if (($message['chat']['type'] ?? 'private') !== 'private') {
            return; // only one-to-one chats
        }

        $chat = TelegramChat::for($chatId);
        $locale = $this->localeFor($chatId, $message['from']['language_code'] ?? null);
        $text = trim((string) ($message['text'] ?? ''));

        if (str_starts_with($text, '/start')) {
            $this->onStart($chatId, trim(substr($text, 6)), $message['from'] ?? [], $locale);

            return;
        }
        if (isset($message['contact'])) {
            $this->onContact($chatId, $message['contact'], $message['from'] ?? [], $locale);

            return;
        }
        if ($text === '/stop') {
            $this->onStop($chatId, $locale);

            return;
        }
        if ($text === '/repair') {
            $this->onRepairCommand($chatId, $locale);

            return;
        }
        if ($chat->currentState() === self::STATE_AWAITING_REPAIR) {
            $this->onRepairMessage($chat, $message, $locale);

            return;
        }

        $this->reply($chatId, __('telegram.help', [], $locale));
    }

    private function onStart(int $chatId, string $payload, array $from, string $locale): void
    {
        if ($payload !== '' && $payload !== 'tenant') {
            $this->linkLandlord($chatId, $payload, $from);

            return;
        }

        $this->reply($chatId, __('telegram.welcome', ['app' => config('app.name')], $locale), [
            'reply_markup' => [
                'keyboard' => [[['text' => __('telegram.share_contact', [], $locale), 'request_contact' => true]]],
                'resize_keyboard' => true,
                'one_time_keyboard' => true,
            ],
        ]);
    }

    private function linkLandlord(int $chatId, string $code, array $from): void
    {
        $row = DB::table('telegram_link_codes')->where('code', $code)->where('expires_at', '>', now())->first();
        $landlord = $row ? User::query()->find($row->user_id) : null;

        if (! $landlord) {
            $this->reply($chatId, __('telegram.code_invalid', [], $this->localeFor($chatId, $from['language_code'] ?? null)));

            return;
        }

        TelegramLink::query()->updateOrCreate(
            ['linkable_type' => $landlord->getMorphClass(), 'linkable_id' => $landlord->id],
            [
                'landlord_id' => $landlord->id,
                'chat_id' => $chatId,
                'telegram_user_id' => $from['id'] ?? null,
                'locale' => $landlord->locale ?: 'am',
                'consented_at' => now(), // the landlord asked for it from the app
            ],
        );
        DB::table('telegram_link_codes')->where('code', $code)->delete();

        $this->reply($chatId, __('telegram.landlord_linked', [], $landlord->locale ?: 'am'));
    }

    /** @param  array<string, mixed>  $contact */
    private function onContact(int $chatId, array $contact, array $from, string $locale): void
    {
        // Only the person's own number: Telegram sets contact.user_id to the sender for "share my contact".
        if (($contact['user_id'] ?? null) !== ($from['id'] ?? false)) {
            $this->reply($chatId, __('telegram.own_contact_only', [], $locale));

            return;
        }

        $phone = PhoneNumber::normalize((string) ($contact['phone_number'] ?? ''));
        $tenants = $phone
            ? Tenant::query()->withoutGlobalScopes()->where('phone', $phone)->get()
            : collect();

        if ($tenants->isEmpty()) {
            $this->reply($chatId, __('telegram.not_found', ['app' => config('app.name')], $locale), ['reply_markup' => ['remove_keyboard' => true]]);

            return;
        }

        foreach ($tenants as $tenant) {
            TelegramLink::query()->updateOrCreate(
                ['linkable_type' => $tenant->getMorphClass(), 'linkable_id' => $tenant->id],
                ['landlord_id' => $tenant->landlord_id, 'chat_id' => $chatId, 'telegram_user_id' => $from['id'], 'locale' => $locale, 'consented_at' => null],
            );
        }

        $this->reply($chatId, __('telegram.consent', ['units' => $this->unitsLabel($tenants)], $locale), [
            'reply_markup' => ['inline_keyboard' => [[
                ['text' => __('telegram.agree', [], $locale), 'callback_data' => 'consent:yes'],
                ['text' => __('telegram.decline', [], $locale), 'callback_data' => 'consent:no'],
            ]]],
        ]);
    }

    /** @param  array<string, mixed>  $query */
    private function onCallback(array $query): void
    {
        $chatId = (int) ($query['message']['chat']['id'] ?? 0);
        $data = (string) ($query['data'] ?? '');
        $locale = $this->localeFor($chatId, $query['from']['language_code'] ?? null);
        $this->telegram->answerCallbackQuery((string) $query['id']);

        $tenantLinks = TelegramLink::query()->where('chat_id', $chatId)->where('linkable_type', (new Tenant)->getMorphClass());

        if ($data === 'consent:yes') {
            (clone $tenantLinks)->whereNull('consented_at')->update(['consented_at' => now()]);
            $this->reply($chatId, __('telegram.agreed', [], $locale), ['reply_markup' => ['remove_keyboard' => true]]);
        } elseif ($data === 'consent:no') {
            (clone $tenantLinks)->whereNull('consented_at')->delete();
            $this->reply($chatId, __('telegram.declined', [], $locale), ['reply_markup' => ['remove_keyboard' => true]]);
        } elseif (preg_match('/^repair:(\d+)$/', $data, $m)) {
            $lease = $this->activeLeases($chatId)->firstWhere('id', (int) $m[1]);
            if ($lease) {
                TelegramChat::for($chatId)->setState(self::STATE_AWAITING_REPAIR, ['lease_id' => $lease->id]);
                $this->reply($chatId, __('telegram.repair_prompt', [], $locale));
            }
        }
    }

    private function onRepairCommand(int $chatId, string $locale): void
    {
        if (! TelegramLink::query()->where('chat_id', $chatId)->where('linkable_type', (new Tenant)->getMorphClass())->whereNotNull('consented_at')->exists()) {
            $this->reply($chatId, __('telegram.link_first', [], $locale));

            return;
        }

        $leases = $this->activeLeases($chatId);
        if ($leases->isEmpty()) {
            $this->reply($chatId, __('telegram.no_active_lease', [], $locale));

            return;
        }

        if ($leases->count() === 1) {
            TelegramChat::for($chatId)->setState(self::STATE_AWAITING_REPAIR, ['lease_id' => $leases->first()->id]);
            $this->reply($chatId, __('telegram.repair_prompt', [], $locale));

            return;
        }

        $this->reply($chatId, __('telegram.choose_unit', [], $locale), [
            'reply_markup' => ['inline_keyboard' => $leases->map(fn (Lease $l) => [[
                'text' => trim($l->unit->label.' · '.($l->unit->property?->name ?? '')),
                'callback_data' => 'repair:'.$l->id,
            ]])->values()->all()],
        ]);
    }

    /** @param  array<string, mixed>  $message */
    private function onRepairMessage(TelegramChat $chat, array $message, string $locale): void
    {
        $lease = $this->activeLeases($chat->chat_id)->firstWhere('id', $chat->state_data['lease_id'] ?? 0);
        if (! $lease) {
            $chat->setState(null);
            $this->reply($chat->chat_id, __('telegram.no_active_lease', [], $locale));

            return;
        }

        $text = trim((string) ($message['text'] ?? $message['caption'] ?? ''));
        $photo = isset($message['photo']) ? end($message['photo']) : null; // largest size last
        $voice = $message['voice'] ?? null;
        $landlordLocale = $lease->landlord?->locale ?: 'am';

        $title = $text !== ''
            ? mb_strimwidth($text, 0, 80, '…')
            : __($photo ? 'telegram.repair_photo_title' : 'telegram.repair_voice_title', [], $landlordLocale);

        DB::transaction(function () use ($lease, $title, $text, $photo, $voice) {
            $request = $this->repairs->open(
                $lease->unit,
                ['title' => $title, 'category' => 'other', 'description' => $text !== '' ? $text : null],
                'tenant',
                $lease,
            );

            if ($photo) {
                $this->photos->storeBytes($this->telegram->downloadFile($photo['file_id']), $request, $lease->landlord_id);
            }
            if ($voice) {
                $this->photos->storeVoice($this->telegram->downloadFile($voice['file_id']), $request, $lease->landlord_id);
            }
        });

        $chat->setState(null);
        $this->reply($chat->chat_id, __('telegram.repair_sent', [], $locale));
    }

    private function onStop(int $chatId, string $locale): void
    {
        TelegramLink::query()->where('chat_id', $chatId)->delete();
        TelegramChat::for($chatId)->setState(null);
        $this->reply($chatId, __('telegram.stopped', [], $locale), ['reply_markup' => ['remove_keyboard' => true]]);
    }

    /** @return Collection<int, Lease> active leases of the tenants linked to this chat */
    private function activeLeases(int $chatId): Collection
    {
        $tenantIds = TelegramLink::query()
            ->where('chat_id', $chatId)
            ->where('linkable_type', (new Tenant)->getMorphClass())
            ->whereNotNull('consented_at')
            ->pluck('linkable_id');

        return Lease::query()->withoutGlobalScopes()
            ->whereIn('tenant_id', $tenantIds)
            ->where('status', Lease::STATUS_ACTIVE)
            ->with([
                'unit' => fn ($q) => $q->withoutGlobalScopes()->with(['property' => fn ($p) => $p->withoutGlobalScopes()]),
                'landlord',
            ])
            ->get();
    }

    private function unitsLabel(Collection $tenants): string
    {
        $leases = Lease::query()->withoutGlobalScopes()
            ->whereIn('tenant_id', $tenants->pluck('id'))
            ->where('status', Lease::STATUS_ACTIVE)
            ->with(['unit' => fn ($q) => $q->withoutGlobalScopes()->with(['property' => fn ($p) => $p->withoutGlobalScopes()])])
            ->get();

        return $leases->isEmpty()
            ? '—'
            : $leases->map(fn (Lease $l) => trim($l->unit->label.' · '.($l->unit->property?->name ?? '')))->implode(', ');
    }

    /** Linked people get their saved language; others get Amharic unless their Telegram is in English. */
    private function localeFor(int $chatId, ?string $telegramLanguage): string
    {
        $linked = TelegramLink::query()->where('chat_id', $chatId)->value('locale');

        return $linked ?: (str_starts_with((string) $telegramLanguage, 'en') ? 'en' : 'am');
    }

    private function reply(int $chatId, string $text, array $extra = []): void
    {
        $this->telegram->sendMessage($chatId, $text, $extra);
    }
}
