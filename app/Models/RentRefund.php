<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLandlord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Rent given back to a tenant; income in reports is net of it. Amount in santim. */
class RentRefund extends Model
{
    use BelongsToLandlord;

    protected $fillable = ['lease_id', 'move_out_id', 'amount', 'refunded_on', 'reason'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'refunded_on' => 'date'];
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }
}
