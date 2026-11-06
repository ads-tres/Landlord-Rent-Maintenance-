<?php

namespace App\Services\Push;

use App\Jobs\SendPush;
use App\Models\Asset;
use App\Models\DeviceToken;
use App\Models\Lease;
use App\Models\Listing;
use App\Models\MaintenanceRequest;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Push alerts to a landlord's phones, with the app screen to open when tapped. */
class PushNotifier
{
    public function __construct(private readonly FcmClient $fcm) {}

    public function toUser(User $user, string $kind, string $body, ?Model $related = null): bool
    {
        if (! $this->fcm->enabled() || ! $user->push_enabled || ! DeviceToken::query()->where('user_id', $user->id)->exists()) {
            return false;
        }

        SendPush::dispatch($user->id, config('app.name'), $body, array_filter([
            'kind' => $kind,
            'route' => self::routeFor($related),
        ]))->afterCommit();

        return true;
    }

    /** The app screen for a notification about $related. */
    public static function routeFor(?Model $related): ?string
    {
        return match (true) {
            $related instanceof MaintenanceRequest => "/repairs/{$related->id}",
            $related instanceof Payment => $related->lease_id ? "/leases/{$related->lease_id}" : '/rent',
            $related instanceof Lease => "/leases/{$related->id}",
            $related instanceof Asset => '/equipment/due',
            $related instanceof Listing => "/listings/{$related->id}",
            default => null,
        };
    }
}
