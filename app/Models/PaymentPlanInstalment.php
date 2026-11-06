<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentPlanInstalment extends Model
{
    public $timestamps = false;

    protected $fillable = ['payment_plan_id', 'due_date', 'amount', 'reminded_at'];

    protected function casts(): array
    {
        return ['due_date' => 'date', 'amount' => 'integer', 'reminded_at' => 'datetime'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PaymentPlan::class, 'payment_plan_id');
    }
}
