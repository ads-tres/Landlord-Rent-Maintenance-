<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\Property;
use App\Models\SmsMessage;
use App\Models\Tenant;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * First-time setup checklist for a landlord. Each step is worked out from their data, so it
 * ticks itself off as they use the app; the optional ones don't block "done".
 */
class OnboardingController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $landlord = $request->user()->actingLandlord();
        $firstLease = Lease::query()->active()->orderBy('id')->first();

        $steps = [
            ['key' => 'profile', 'done' => filled($landlord->name), 'optional' => false],
            ['key' => 'property', 'done' => Property::query()->exists(), 'optional' => false],
            ['key' => 'units', 'done' => Unit::query()->exists(), 'optional' => false],
            ['key' => 'tenant', 'done' => Tenant::query()->exists(), 'optional' => false],
            ['key' => 'lease', 'done' => $firstLease !== null || Lease::query()->exists(), 'optional' => false],
            ['key' => 'payment', 'done' => Payment::query()->exists(), 'optional' => false],
            ['key' => 'tenant_page', 'done' => SmsMessage::query()->whereIn('kind', [SmsMessage::KIND_PORTAL, SmsMessage::KIND_REPAIR_LINK])->exists(), 'optional' => false],
            ['key' => 'telegram', 'done' => $landlord->telegramLink()->exists(), 'optional' => true],
            ['key' => 'caretaker', 'done' => $landlord->team()->exists(), 'optional' => true],
        ];
        $required = array_filter($steps, fn ($s) => ! $s['optional']);

        return response()->json(['data' => [
            'steps' => $steps,
            'done_count' => count(array_filter($required, fn ($s) => $s['done'])),
            'total' => count($required),
            'completed' => collect($required)->every('done'),
            'dismissed' => $landlord->onboarding_dismissed_at !== null,
            'first_lease_id' => $firstLease?->id,
        ]]);
    }

    /** POST /onboarding/dismiss — hide the checklist from Home. */
    public function dismiss(Request $request): JsonResponse
    {
        $request->user()->forceFill(['onboarding_dismissed_at' => now()])->save();

        return $this->show($request);
    }
}
