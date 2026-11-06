<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Services\Account\AccountDeletion;
use App\Services\Push\FcmClient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'phone' => $this->phone,
            'name' => $this->name,
            'id_number' => $this->isCaretaker() ? null : $this->id_number,
            'address' => $this->isCaretaker() ? null : $this->address,
            'locale' => $this->locale,
            'calendar_pref' => $this->calendar_pref,
            'sms_reminders' => (bool) $this->sms_reminders,
            'push_enabled' => (bool) $this->push_enabled,
            'push_available' => app(FcmClient::class)->enabled(),
            'role' => $this->role,
            'deletion_scheduled_for' => $this->deletion_requested_at?->copy()->addDays(AccountDeletion::GRACE_DAYS)->toDateString(),
            'landlord_name' => $this->isCaretaker() ? ($this->employer?->name ?: $this->employer?->phone) : null,
            'online_rent_available' => filled(config('chapa.secret_key')),
            'online_rent_enabled' => $this->actingLandlord()->acceptsOnlineRent(),
            'telegram_linked' => $this->telegramLink()->exists(),
            'telegram_available' => filled(config('telegram.token')) && filled(config('telegram.username')),
        ];
    }
}
