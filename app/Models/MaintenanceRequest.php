<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLandlord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class MaintenanceRequest extends Model
{
    use BelongsToLandlord;

    public const STATUSES = ['open', 'in_progress', 'done'];

    public const CATEGORIES = ['water', 'electric', 'plumbing', 'door_lock', 'roof', 'appliance', 'other'];

    protected $fillable = [
        'unit_id', 'tenant_id', 'lease_id', 'title', 'category', 'description', 'resolution_note', 'cost',
        'status', 'source', 'opened_at', 'closed_at',
    ];

    protected $attributes = ['status' => 'open', 'category' => 'other', 'source' => 'landlord'];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'cost' => 'integer',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
