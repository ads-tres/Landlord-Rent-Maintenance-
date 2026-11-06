<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLandlord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

/** End of a lease: condition check, deposit deductions and the settlement. Amounts in santim. */
class MoveOut extends Model
{
    use BelongsToLandlord;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SETTLED = 'settled';

    protected $fillable = ['lease_id', 'moved_out_on', 'condition_notes', 'keys_returned'];

    protected $attributes = ['status' => self::STATUS_DRAFT];

    protected static function booted(): void
    {
        static::creating(function (MoveOut $m) {
            $m->statement_token ??= Str::lower(Str::random(10));
        });
    }

    protected function casts(): array
    {
        return [
            'moved_out_on' => 'date',
            'keys_returned' => 'boolean',
            'settled_at' => 'datetime',
            'deposit_held' => 'integer',
            'deposit_applied' => 'integer',
            'deposit_kept' => 'integer',
            'refund_amount' => 'integer',
            'balance_owed' => 'integer',
        ];
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function landlord(): BelongsTo
    {
        return $this->belongsTo(User::class, 'landlord_id');
    }

    public function deductions(): HasMany
    {
        return $this->hasMany(MoveOutDeduction::class)->orderBy('id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function isSettled(): bool
    {
        return $this->status === self::STATUS_SETTLED;
    }

    public function statementUrl(): string
    {
        return route('move-outs.statement', $this->statement_token);
    }
}
