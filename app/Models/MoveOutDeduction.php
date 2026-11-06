<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLandlord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MoveOutDeduction extends Model
{
    use BelongsToLandlord;

    /** unpaid_rent and unpaid_utility come from the lease's charges; the rest are added by the landlord. */
    public const KINDS = ['unpaid_rent', 'unpaid_utility', 'damage', 'cleaning', 'keys', 'other'];

    public const MANUAL_KINDS = ['damage', 'cleaning', 'keys', 'other'];

    protected $fillable = ['move_out_id', 'charge_id', 'kind', 'description', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    public function moveOut(): BelongsTo
    {
        return $this->belongsTo(MoveOut::class);
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }

    public function isFromCharge(): bool
    {
        return $this->charge_id !== null;
    }
}
