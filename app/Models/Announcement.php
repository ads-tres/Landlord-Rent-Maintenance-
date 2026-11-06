<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLandlord;
use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    use BelongsToLandlord;

    protected $fillable = ['property_id', 'body', 'telegram_count', 'sms_count', 'sms_segments'];
}
