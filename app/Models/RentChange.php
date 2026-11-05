<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLandlord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RentChange extends Model
{
    use BelongsToLandlord;

    protected $fillable = ['lease_id', 'old_rent', 'new_rent', 'effective_date', 'cap_percent'];

    protected function casts(): array
    {
        return [
            'old_rent' => 'integer',
            'new_rent' => 'integer',
            'effective_date' => 'date',
            'cap_percent' => 'decimal:2',
        ];
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }
}
