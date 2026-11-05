<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLandlord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One row per lease per month (and per type: rent, utility, other). */
class Charge extends Model
{
    use BelongsToLandlord;

    public const STATUS_DUE = 'due';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_PAID = 'paid';

    public const STATUS_LATE = 'late';

    protected $fillable = ['lease_id', 'period', 'type', 'amount_due', 'due_date', 'status'];

    protected $attributes = ['type' => 'rent', 'status' => self::STATUS_DUE];

    protected function casts(): array
    {
        return [
            'amount_due' => 'integer',
            'due_date' => 'date',
        ];
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function amountPaid(): int
    {
        return (int) $this->allocations()->sum('amount');
    }

    /** Recomputes due / partial / paid / late from the money allocated and the due date. */
    public function refreshStatus(): void
    {
        $paid = $this->amountPaid();

        $this->status = match (true) {
            $paid >= $this->amount_due => self::STATUS_PAID,
            $this->due_date->lt(today()) => self::STATUS_LATE,
            $paid > 0 => self::STATUS_PARTIAL,
            default => self::STATUS_DUE,
        };
        $this->save();
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }
}
