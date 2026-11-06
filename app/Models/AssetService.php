<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLandlord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetService extends Model
{
    use BelongsToLandlord;

    protected $fillable = ['asset_id', 'serviced_on', 'cost', 'notes'];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    protected function casts(): array
    {
        return ['serviced_on' => 'date', 'cost' => 'integer'];
    }
}
