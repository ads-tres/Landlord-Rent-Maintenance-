<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLandlord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Records when a tenant was told their data is stored (Proclamation 1321/2024). */
class Consent extends Model
{
    use BelongsToLandlord;

    public const PRIVACY_NOTICE_VERSION = '2026-11';

    protected $fillable = ['tenant_id', 'kind', 'notice_version', 'locale', 'given_at'];

    protected function casts(): array
    {
        return ['given_at' => 'datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
