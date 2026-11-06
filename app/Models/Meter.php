<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLandlord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A shared electricity or water meter in a property, split across some of its units. */
class Meter extends Model
{
    use BelongsToLandlord;

    public const KINDS = ['electricity', 'water'];

    public const METHODS = ['equal', 'percent', 'submeter'];

    protected $fillable = ['property_id', 'name', 'kind', 'split_method'];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function meterUnits(): HasMany
    {
        return $this->hasMany(MeterUnit::class);
    }

    public function bills(): HasMany
    {
        return $this->hasMany(UtilityBill::class);
    }
}
