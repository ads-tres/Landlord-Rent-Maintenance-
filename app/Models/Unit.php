<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLandlord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** A room or apartment inside a property. */
class Unit extends Model
{
    use BelongsToLandlord, HasFactory;

    public const STATUS_VACANT = 'vacant';

    public const STATUS_OCCUPIED = 'occupied';

    protected $fillable = ['label', 'bedrooms', 'status'];

    protected $attributes = ['status' => self::STATUS_VACANT];

    protected function casts(): array
    {
        return ['bedrooms' => 'integer', 'vacant_since' => 'datetime', 'vacancy_reminded_months' => 'integer'];
    }

    protected static function booted(): void
    {
        // Track how long a unit has been empty (for the 6-month vacancy rule).
        static::saving(function (Unit $unit) {
            if ($unit->isDirty('status') || ! $unit->exists) {
                if ($unit->status === self::STATUS_VACANT) {
                    $unit->vacant_since ??= now();
                } else {
                    $unit->vacant_since = null;
                    $unit->vacancy_reminded_months = 0;
                }
            }
        });
    }

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    public function openListing(): HasOne
    {
        return $this->hasOne(Listing::class)->whereIn('status', [Listing::STATUS_DRAFT, Listing::STATUS_LIVE, Listing::STATUS_SUSPENDED])->latestOfMany();
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function leases(): HasMany
    {
        return $this->hasMany(Lease::class);
    }

    public function activeLease(): HasOne
    {
        return $this->hasOne(Lease::class)->where('status', Lease::STATUS_ACTIVE)->latestOfMany('start_date');
    }

    public function maintenanceRequests(): HasMany
    {
        return $this->hasMany(MaintenanceRequest::class);
    }
}
