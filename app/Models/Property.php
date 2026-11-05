<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLandlord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A compound or building. */
class Property extends Model
{
    use BelongsToLandlord, HasFactory;

    protected $fillable = ['name', 'sub_city', 'woreda', 'address'];

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    public function meters(): HasMany
    {
        return $this->hasMany(Meter::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    public function announcements(): HasMany
    {
        return $this->hasMany(Announcement::class);
    }

    public function vacantUnits(): HasMany
    {
        return $this->units()->where('status', Unit::STATUS_VACANT);
    }
}
