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

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }
}
