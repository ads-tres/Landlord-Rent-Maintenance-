<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLandlord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An agreed schedule for paying off arrears in instalments. Amounts in santim. */
class PaymentPlan extends Model
{
    use BelongsToLandlord;

    protected $fillable = ['lease_id', 'total', 'starts_on', 'status'];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return ['total' => 'integer', 'starts_on' => 'date'];
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function instalments(): HasMany
    {
        return $this->hasMany(PaymentPlanInstalment::class)->orderBy('due_date');
    }
}
