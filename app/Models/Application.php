<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLandlord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Someone applying for a listing. Only phone-verified applications reach the landlord. */
class Application extends Model
{
    use BelongsToLandlord;

    public const STATUSES = ['new', 'shortlisted', 'rejected', 'approved'];

    /** Days a not-approved application is kept before it is deleted. */
    public const KEEP_DAYS = 30;

    protected $fillable = ['listing_id', 'full_name', 'phone', 'phone_verified_at', 'household_size', 'move_in_date', 'message', 'status', 'lease_id', 'delete_after'];

    protected $attributes = ['status' => 'new'];

    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'datetime',
            'move_in_date' => 'date',
            'delete_after' => 'datetime',
            'household_size' => 'integer',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }
}
