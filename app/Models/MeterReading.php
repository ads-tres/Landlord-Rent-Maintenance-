<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLandlord;
use Illuminate\Database\Eloquent\Model;

class MeterReading extends Model
{
    use BelongsToLandlord;

    protected $fillable = ['utility_bill_id', 'unit_id', 'previous', 'current'];

    protected function casts(): array
    {
        return ['previous' => 'float', 'current' => 'float'];
    }
}
