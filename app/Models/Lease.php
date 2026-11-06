<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLandlord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Lease extends Model
{
    use BelongsToLandlord, HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ENDED = 'ended';

    protected $fillable = [
        'unit_id', 'tenant_id', 'start_date', 'end_date', 'terminated_on', 'monthly_rent', 'deposit',
        'due_day', 'due_calendar', 'condition_notes', 'registered_at', 'status', 'repair_token', 'pay_token',
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
            'terminated_on' => 'date',
            'registered_at' => 'datetime',
            'monthly_rent' => 'integer',
            'deposit' => 'integer',
            'due_day' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Lease $lease) {
            $lease->repair_token ??= static::newRepairToken();
            $lease->pay_token ??= static::newRepairToken();
        });
    }

    public static function newRepairToken(): string
    {
        do {
            $token = Str::lower(Str::random(10));
        } while (static::query()->withoutGlobalScopes()->where('repair_token', $token)->exists());

        return $token;
    }

    public function repairUrl(): ?string
    {
        return $this->repair_token ? route('repairs.public.show', $this->repair_token) : null;
    }

    /** Tenant's online rent page, shown only when the landlord has online rent on. */
    public function payUrl(): ?string
    {
        return $this->pay_token ? route('pay.show', $this->pay_token) : null;
    }

    public function maintenanceRequests(): HasMany
    {
        return $this->hasMany(MaintenanceRequest::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }

    /** Active leases whose end date falls within the next $days days. */
    public function scopeEndingWithin(Builder $query, int $days): void
    {
        $query->active()->whereBetween('end_date', [today(), today()->addDays($days)]);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function moveOut(): HasOne
    {
        return $this->hasOne(MoveOut::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function rentChanges(): HasMany
    {
        return $this->hasMany(RentChange::class);
    }
}
