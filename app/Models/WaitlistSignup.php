<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WaitlistSignup extends Model
{
    protected $fillable = [
        'phone', 'name', 'unit_count', 'current_tracking', 'biggest_problem',
        'monthly_price_birr', 'locale', 'source', 'status', 'admin_notes', 'landlord_id',
    ];

    public const STATUSES = ['new', 'contacted', 'pilot', 'declined'];
}
