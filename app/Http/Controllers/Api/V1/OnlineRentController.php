<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lease;
use App\Models\PayoutAccount;
use App\Models\SmsMessage;
use App\Services\Chapa\ChapaClient;
use App\Services\Sms\SmsNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/** The landlord's online rent setup: payout bank account (a Chapa sub-account) and tenant pay links. */
class OnlineRentController extends Controller
{
    public function __construct(private readonly ChapaClient $chapa) {}

    /** GET /chapa/banks */
    public function banks(): JsonResponse
    {
        abort_unless($this->chapa->enabled(), 503, __('chapa.off'));

        try {
            return response()->json(['data' => $this->chapa->banks()]);
        } catch (RuntimeException) {
            abort(502, __('chapa.unavailable'));
        }
    }

    /** GET /payout-account */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present($request->user()->payoutAccount)]);
    }

    /** PUT /payout-account — creates the Chapa sub-account that receives this landlord's online rent. */
    public function update(Request $request): JsonResponse
    {
        abort_unless($this->chapa->enabled(), 503, __('chapa.off'));
        $data = $request->validate([
            'bank_code' => ['required', 'string', 'max:50'],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'account_name' => ['required', 'string', 'max:100'],
            'account_number' => ['required', 'string', 'regex:/^[0-9]{6,20}$/'],
        ]);

        $user = $request->user();
        $account = PayoutAccount::query()->firstOrNew(['landlord_id' => $user->id]);
        $account->fill($data + ['status' => 'pending', 'error' => null, 'chapa_subaccount_id' => null]);
        $account->landlord_id = $user->id;
        $account->save();
        $user->unsetRelation('payoutAccount'); // later checks must see the new account

        try {
            $id = $this->chapa->createSubaccount($user->name ?: $data['account_name'], $data['account_name'], $data['bank_code'], $data['account_number']);
            $account->update(['chapa_subaccount_id' => $id, 'status' => 'active']);
        } catch (RuntimeException $e) {
            $account->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
            $user->update(['online_rent_enabled' => false]);

            return response()->json(['message' => __('chapa.subaccount_failed'), 'data' => $this->present($account)], 422);
        }

        return response()->json(['data' => $this->present($account->refresh())]);
    }

    /** POST /leases/{id}/pay-link/send */
    public function sendPayLink(Request $request, Lease $lease, SmsNotifier $sms): JsonResponse
    {
        Gate::authorize('update', $lease);
        abort_unless($lease->status === Lease::STATUS_ACTIVE && $request->user()->acceptsOnlineRent(), 409, __('chapa.off'));

        $lease->loadMissing(['tenant', 'unit']);
        $locale = $request->user()->locale ?: 'am';
        $message = $sms->toTenant($lease->tenant, SmsMessage::KIND_PAY_LINK, __('chapa.sms_pay_link', [
            'unit' => $lease->unit->label,
            'url' => $lease->payUrl(),
            'app' => config('app.name'),
        ], $locale), $lease);

        return response()->json(['data' => ['status' => $message->fresh()->status, 'pay_url' => $lease->payUrl()]]);
    }

    private function present(?PayoutAccount $account): ?array
    {
        return $account ? [
            'bank_code' => $account->bank_code,
            'bank_name' => $account->bank_name,
            'account_name' => $account->account_name,
            'account_number' => $account->maskedNumber(),
            'status' => $account->status,
        ] : null;
    }
}
