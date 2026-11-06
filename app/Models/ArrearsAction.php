<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLandlord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArrearsAction extends Model
{
    use BelongsToLandlord;

    public const KINDS = ['reminder', 'notice', 'demand_letter', 'plan', 'note'];

    protected $fillable = ['lease_id', 'user_id', 'kind', 'amount_owed', 'days_overdue', 'note'];

    protected function casts(): array
    {
        return ['amount_owed' => 'integer', 'days_overdue' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
