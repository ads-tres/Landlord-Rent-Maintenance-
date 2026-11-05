<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLandlord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Photos and receipt PDFs, attached to any model. */
class Attachment extends Model
{
    use BelongsToLandlord;

    protected $fillable = ['path', 'mime'];

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }
}
