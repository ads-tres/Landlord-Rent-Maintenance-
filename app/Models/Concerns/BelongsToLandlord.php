<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Scopes every query to the authenticated landlord and stamps landlord_id
 * on create, so one landlord can never read or write another's data.
 *
 * Outside an authenticated request (scheduler, queue, seeders) no scope is
 * applied; those callers must filter by landlord_id themselves.
 */
trait BelongsToLandlord
{
    public static function bootBelongsToLandlord(): void
    {
        static::addGlobalScope('landlord', function (Builder $builder) {
            $user = Auth::user();

            if ($user instanceof User) {
                $builder->where($builder->qualifyColumn('landlord_id'), $user->landlordId());
            }
        });

        static::creating(function ($model) {
            $user = Auth::user();

            if (empty($model->landlord_id) && $user instanceof User) {
                $model->landlord_id = $user->landlordId();
            }
        });
    }

    public function landlord(): BelongsTo
    {
        return $this->belongsTo(User::class, 'landlord_id');
    }
}
