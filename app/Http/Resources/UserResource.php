<?php

namespace App\Http\Resources;

use App\Models\User;
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
            'locale' => $this->locale,
            'calendar_pref' => $this->calendar_pref,
            'sms_reminders' => (bool) $this->sms_reminders,
            'role' => $this->role,
        ];
    }
}
