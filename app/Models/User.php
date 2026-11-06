<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_LANDLORD = 'landlord';

    /** Works on a landlord's data with limited rights (logs payments, handles repairs). */
    public const ROLE_CARETAKER = 'caretaker';

    public const MAX_CARETAKERS = 5;

    /** Runs the pilot: admin panel only, never sees the landlord API data of others. */
    public const ROLE_ADMIN = 'admin';

    protected $fillable = ['phone', 'name', 'email', 'locale', 'calendar_pref', 'sms_reminders', 'online_rent_enabled', 'role', 'is_pilot', 'phone_verified_at'];

    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'datetime',
            'sms_reminders' => 'boolean',
            'is_pilot' => 'boolean',
            'online_rent_enabled' => 'boolean',
            'removed_at' => 'datetime',
        ];
    }

    /** The landlord whose data this user works on: themself, or the employer for a caretaker. */
    public function landlordId(): int
    {
        return $this->landlord_id ?? $this->id;
    }

    public function isCaretaker(): bool
    {
        return $this->role === self::ROLE_CARETAKER;
    }

    /** The landlord a caretaker works for. */
    public function employer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'landlord_id');
    }

    /** The landlord in whose name this user acts (settings, online rent, sender name). */
    public function actingLandlord(): self
    {
        return $this->isCaretaker() && $this->employer ? $this->employer : $this;
    }

    /** A landlord's caretakers. */
    public function team(): HasMany
    {
        return $this->hasMany(self::class, 'landlord_id')->where('role', self::ROLE_CARETAKER);
    }

    public function payoutAccount(): HasOne
    {
        return $this->hasOne(PayoutAccount::class, 'landlord_id')->withoutGlobalScopes();
    }

    /** Online rent works for this landlord: Chapa configured, payout account active, switched on. */
    public function acceptsOnlineRent(): bool
    {
        return filled(config('chapa.secret_key'))
            && $this->online_rent_enabled
            && (bool) $this->payoutAccount?->isActive();
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
