<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_LANDLORD = 'landlord';

    /** Runs the pilot: admin panel only, never sees the landlord API data of others. */
    public const ROLE_ADMIN = 'admin';

    protected $fillable = ['phone', 'name', 'email', 'locale', 'calendar_pref', 'sms_reminders', 'role', 'is_pilot', 'phone_verified_at'];

    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'datetime',
            'sms_reminders' => 'boolean',
            'is_pilot' => 'boolean',
        ];
    }

    /**
     * The landlord whose data this user works on. Today every user is a
     * landlord; managers and caretakers will map to their landlord later.
     */
    public function landlordId(): int
    {
        return $this->id;
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class, 'landlord_id');
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class, 'landlord_id');
    }

    public function telegramLink(): MorphOne
    {
        return $this->morphOne(TelegramLink::class, 'linkable')->whereNotNull('consented_at');
    }
}
