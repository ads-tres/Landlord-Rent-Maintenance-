<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLandlord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A lease extended to a new end date, with the rent from then on. Amounts in santim. */
class LeaseRenewal extends Model
{
    use BelongsToLandlord;

    protected $fillable = ['lease_id', 'previous_end_date', 'new_end_date', 'old_rent', 'new_rent', 'rent_from', 'cap_percent'];

    protected function casts(): array
    {
        return [
            'previous_end_date' => 'date',
            'new_end_date' => 'date',
            'rent_from' => 'date',
            'old_rent' => 'integer',
            'new_rent' => 'integer',
            'cap_percent' => 'decimal:2',
        ];
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }
}
