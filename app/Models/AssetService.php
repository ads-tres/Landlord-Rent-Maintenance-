<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLandlord;
use Illuminate\Database\Eloquent\Model;

class AssetService extends Model
{
    use BelongsToLandlord;

    protected $fillable = ['asset_id', 'serviced_on', 'cost', 'notes'];

    protected function casts(): array
    {
        return ['serviced_on' => 'date', 'cost' => 'integer'];
    }
}
