<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Api\V1;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Caretakers work on their landlord's data but can't touch money settings, reports,
 * the team, or anything that creates, ends or deletes leases, properties and payments.
 */
class CaretakerLimits
{
    /** Controller actions only the landlord may use. */
    public const LANDLORD_ONLY = [
        V1\ReportController::class.'@quarterly',
        V1\ExpenseController::class.'@*',
        V1\OnlineRentController::class.'@show',
        V1\OnlineRentController::class.'@update',
        V1\TeamController::class.'@*',
        V1\TelegramLinkController::class.'@*',
        V1\PropertyController::class.'@store',
        V1\PropertyController::class.'@destroy',
        V1\UnitController::class.'@store',
        V1\UnitController::class.'@destroy',
        V1\TenantController::class.'@destroy',
        V1\TenantPrivacyController::class.'@*',
        V1\LeaseController::class.'@store',
        V1\LeaseController::class.'@end',
        V1\LeaseController::class.'@rentChange',
        V1\LeaseController::class.'@activate',
        V1\LeaseRenewalController::class.'@store',
        V1\ArrearsController::class.'@demandLetter',
        V1\ArrearsController::class.'@storePlan',
        V1\ArrearsController::class.'@cancelPlan',
        V1\MoveOutController::class.'@settle',
        V1\ListingController::class.'@store',
        V1\ListingController::class.'@update',
        V1\ListingController::class.'@updateApplication',
        V1\PaymentController::class.'@destroy',
        V1\MeterController::class.'@destroy',
        V1\UtilityBillController::class.'@destroy',
        V1\AssetController::class.'@destroy',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->isCaretaker()) {
            return $next($request);
        }

        abort_if($user->removed_at !== null || ! $user->landlord_id, 403, __('team.removed'));
        abort_if(self::isLandlordOnly((string) $request->route()?->getActionName()), 403, __('team.landlord_only'));

        return $next($request);
    }

    public static function isLandlordOnly(string $action): bool
    {
        [$controller] = explode('@', $action) + [''];

        return in_array($action, self::LANDLORD_ONLY, true) || in_array($controller.'@*', self::LANDLORD_ONLY, true);
    }
}
