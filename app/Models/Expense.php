<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLandlord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Money the landlord spent on a property. Amounts in santim. */
class Expense extends Model
{
    use BelongsToLandlord;

    public const CATEGORIES = [
        'repair', 'maintenance', 'land_lease', 'utility', 'tax', 'insurance', 'security_cleaning', 'other',
    ];

    protected $fillable = ['property_id', 'unit_id', 'category', 'amount', 'spent_on', 'description', 'vendor', 'source_type', 'source_id'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'spent_on' => 'date'];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function isAutomatic(): bool
    {
        return $this->source_type !== null;
    }
}
