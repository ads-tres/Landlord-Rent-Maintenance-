<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLandlord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Tenant extends Model
{
    use BelongsToLandlord, HasFactory;

    protected $fillable = ['full_name', 'phone', 'id_number', 'address'];

    protected $hidden = ['id_number'];

    protected function casts(): array
    {
        // ID numbers are personal data under Proclamation 1321/2024: encrypt at rest.
        return ['id_number' => 'encrypted'];
    }

    public function leases(): HasMany
    {
        return $this->hasMany(Lease::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function consents(): HasMany
    {
        return $this->hasMany(Consent::class);
    }

    public function telegramLink(): MorphOne
    {
        return $this->morphOne(TelegramLink::class, 'linkable')->whereNotNull('consented_at');
    }
}
