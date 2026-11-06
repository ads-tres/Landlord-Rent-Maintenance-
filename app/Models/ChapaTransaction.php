<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLandlord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One Chapa checkout. Marked paid only after Chapa's verify endpoint confirms it. */
class ChapaTransaction extends Model
{
    use BelongsToLandlord;

    protected $fillable = ['lease_id', 'tenant_id', 'tx_ref', 'amount', 'fee', 'status', 'checkout_url', 'chapa_reference', 'verified_at', 'payment_id', 'raw'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'fee' => 'integer', 'verified_at' => 'datetime', 'raw' => 'array'];
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }
}
