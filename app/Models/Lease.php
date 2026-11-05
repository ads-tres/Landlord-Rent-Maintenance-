<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLandlord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lease extends Model
{
    use BelongsToLandlord, HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ENDED = 'ended';

    /** Addis directive 7/2024: leases run at least two years. */
    public const MIN_TERM_YEARS = 2;

    protected $fillable = [
        'unit_id', 'tenant_id', 'start_date', 'end_date', 'monthly_rent', 'deposit',
        'due_day', 'due_calendar', 'condition_notes', 'registered_at', 'status',
    ];

    protected $attributes = [
        'deposit' => 0,
        'due_day' => 1,
        'due_calendar' => 'gregorian',
        'status' => self::STATUS_ACTIVE,
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'registered_at' => 'datetime',
            'monthly_rent' => 'integer',
            'deposit' => 'integer',
            'due_day' => 'integer',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class);
    }

    public function rentChanges(): HasMany
    {
        return $this->hasMany(RentChange::class);
    }
}
