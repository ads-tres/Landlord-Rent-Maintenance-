<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLandlord;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Equipment that needs regular service: generator, water tank, pump. Backup power and
 * water are among the biggest rent factors in Addis, so they should keep working.
 */
class Asset extends Model
{
    use BelongsToLandlord;

    public const KINDS = ['generator', 'water_tank', 'pump', 'other'];

    protected $fillable = ['property_id', 'kind', 'name', 'service_interval_days', 'last_serviced_on', 'notes'];

    protected function casts(): array
    {
        return [
            'last_serviced_on' => 'date',
            'last_reminded_on' => 'date',
            'service_interval_days' => 'integer',
        ];
    }

    /** Next service date; null until the first service is logged (treated as due now). */
    public function nextServiceOn(): ?CarbonImmutable
    {
        return $this->last_serviced_on
            ? CarbonImmutable::parse($this->last_serviced_on)->addDays($this->service_interval_days)
            : null;
    }

    public function isDueWithin(int $days): bool
    {
        $next = $this->nextServiceOn();

        return $next === null || $next->lte(today()->addDays($days));
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(AssetService::class);
    }
}
