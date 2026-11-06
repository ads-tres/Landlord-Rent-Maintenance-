<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLandlord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

/** A vacant unit offered for rent through a shareable page (/l/{slug}). Amounts in santim. */
class Listing extends Model
{
    use BelongsToLandlord;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_LIVE = 'live';

    public const STATUS_CLOSED = 'closed';

    /** Hidden after several reports, until the landlord or admin looks at it. */
    public const STATUS_SUSPENDED = 'suspended';

    public const DAYS_LIVE = 30;

    public const REPORTS_TO_SUSPEND = 3;

    protected $fillable = ['unit_id', 'asking_rent', 'available_from', 'description', 'status', 'published_at', 'expires_at', 'closed_at'];

    protected $attributes = ['status' => self::STATUS_DRAFT];

    protected function casts(): array
    {
        return [
            'asking_rent' => 'integer',
            'available_from' => 'date',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
            'closed_at' => 'datetime',
            'report_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Listing $listing) {
            do {
                $slug = Str::lower(Str::random(8));
            } while (static::query()->withoutGlobalScopes()->where('slug', $slug)->exists());
            $listing->slug ??= $slug;
        });
    }

    public function isLive(): bool
    {
        return $this->status === self::STATUS_LIVE && (! $this->expires_at || $this->expires_at->isFuture());
    }

    public function url(): string
    {
        return route('listings.public.show', $this->slug);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
