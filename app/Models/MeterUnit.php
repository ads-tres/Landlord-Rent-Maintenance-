<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLandlord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeterUnit extends Model
{
    use BelongsToLandlord;

    protected $fillable = ['meter_id', 'unit_id', 'share_percent'];

    protected function casts(): array
    {
        return ['share_percent' => 'float'];
    }

    public function meter(): BelongsTo
    {
        return $this->belongsTo(Meter::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
