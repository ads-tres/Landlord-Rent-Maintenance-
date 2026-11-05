<?php

namespace App\Services\Notifications;

use App\Models\Announcement;
use App\Models\Lease;
use App\Models\Property;
use App\Models\SmsMessage;
use App\Models\TelegramLink;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Sms\SmsNotifier;
use App\Services\Telegram\TelegramClient;
use Illuminate\Support\Collection;

/**
 * One message to every current tenant of a property (e.g. "No water tomorrow 8–12").
 * Free for tenants on Telegram; the preview shows how many SMS it costs first.
 */
class AnnouncementService
{
    public function __construct(
        private readonly SmsNotifier $sms,
        private readonly TelegramClient $telegram,
    ) {}

    /** @return array{recipients: int, telegram: int, sms: int, segments_per_sms: int, sms_segments: int, estimated_cost: ?int, text: string} */
    public function preview(Property $property, string $body, User $landlord): array
    {
        $text = $this->text($property, $body, $landlord);
        $tenants = $this->tenants($property);
        $onTelegram = $this->telegram->enabled()
            ? $tenants->filter(fn (Tenant $t) => TelegramLink::activeFor($t) !== null)->count()
            : 0;
        $smsCount = $tenants->count() - $onTelegram;
        $segments = SmsMessage::segmentsFor($text);
        $price = config('sms.price_per_segment_santim');

        return [
            'recipients' => $tenants->count(),
            'telegram' => $onTelegram,
            'sms' => $smsCount,
            'segments_per_sms' => $segments,
            'sms_segments' => $smsCount * $segments,
            'estimated_cost' => $price !== null ? (int) ($smsCount * $segments * (int) $price) : null,
            'text' => $text,
        ];
    }

    public function send(Property $property, string $body, User $landlord): Announcement
    {
        $preview = $this->preview($property, $body, $landlord);

        foreach ($this->tenants($property) as $tenant) {
            $this->sms->toTenant($tenant, SmsMessage::KIND_ANNOUNCEMENT, $preview['text']);
        }

        $announcement = new Announcement([
            'property_id' => $property->id,
            'body' => $body,
            'telegram_count' => $preview['telegram'],
            'sms_count' => $preview['sms'],
            'sms_segments' => $preview['sms_segments'],
        ]);
        $announcement->landlord_id = $property->landlord_id;
        $announcement->save();

        return $announcement;
    }

    /** @return Collection<int, Tenant> tenants with an active lease in the property */
    private function tenants(Property $property): Collection
    {
        return Lease::query()
            ->where('status', Lease::STATUS_ACTIVE)
            ->whereHas('unit', fn ($q) => $q->where('property_id', $property->id))
            ->with('tenant')
            ->get()
            ->pluck('tenant')
            ->filter()
            ->unique('id')
            ->values();
    }

    private function text(Property $property, string $body, User $landlord): string
    {
        return __('sms.announcement', [
            'property' => $property->name,
            'body' => trim($body),
            'landlord' => $landlord->name ?: config('app.name'),
        ], $landlord->locale ?: 'am');
    }
}
