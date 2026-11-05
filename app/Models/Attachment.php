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

    /** Files are stored on the private local disk and served only through the API. */
    public const DISK = 'local';

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }
}
