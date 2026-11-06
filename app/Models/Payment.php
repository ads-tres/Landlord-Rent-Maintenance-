<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLandlord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Payment extends Model
{
    use BelongsToLandlord;

    public const METHODS = ['cash', 'telebirr', 'bank', 'chapa', 'deposit'];

    /** Methods a landlord can log by hand; chapa payments arrive by webhook (phase 2). */
    public const MANUAL_METHODS = ['cash', 'telebirr', 'bank'];

    protected $fillable = ['tenant_id', 'lease_id', 'amount', 'paid_at', 'method', 'reference', 'notes', 'source', 'receipt_token', 'client_ref'];

    protected $attributes = ['source' => 'manual'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'paid_at' => 'date',
        ];
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function receiptNumber(): string
    {
        return 'R-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function receiptUrl(): string
    {
        return route('receipts.show', $this->receipt_token);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
