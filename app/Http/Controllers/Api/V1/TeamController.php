<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SmsMessage;
use App\Models\User;
use App\Rules\EthiopianPhone;
use App\Services\Sms\SmsNotifier;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/** The landlord's caretakers: invite by phone, list, remove. */
class TeamController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->team()
            ->whereNull('removed_at')
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => $this->present($u))]);
    }

    /** POST /team {phone, name} — texts the caretaker how to log in. */
    public function store(Request $request, SmsNotifier $sms): JsonResponse
    {
        $landlord = $request->user();
        if (is_string($request->input('phone')) && ($p = PhoneNumber::normalize($request->input('phone')))) {
            $request->merge(['phone' => $p]);
        }
        $data = $request->validate([
            'phone' => ['required', new EthiopianPhone],
            'name' => ['required', 'string', 'max:100'],
        ]);
        $phone = $data['phone'];
        if ($phone === $landlord->phone) {
            throw ValidationException::withMessages(['phone' => __('team.is_you')]);
        }
        if ($landlord->team()->whereNull('removed_at')->count() >= User::MAX_CARETAKERS) {
            throw ValidationException::withMessages(['phone' => __('team.too_many', ['max' => User::MAX_CARETAKERS])]);
        }

        $user = User::query()->where('phone', $phone)->first();
        if ($user && ! ($user->isCaretaker() && ($user->landlord_id === $landlord->id || $user->removed_at))) {
            // Already a landlord (or someone else's active caretaker): one account, one role.
            throw ValidationException::withMessages(['phone' => __('team.phone_taken')]);
        }

        $user ??= new User(['phone' => $phone]);
        $user->forceFill([
            'name' => $data['name'],
            'role' => User::ROLE_CARETAKER,
            'landlord_id' => $landlord->id,
            'removed_at' => null,
            'locale' => $user->locale ?? $landlord->locale,
            'calendar_pref' => $landlord->calendar_pref,
        ])->save();

        $sms->queue($landlord->id, $phone, SmsMessage::KIND_TEAM_INVITE, __('sms.team_invite', [
            'landlord' => $landlord->name ?: $landlord->phone,
            'app' => config('app.name'),
        ], $user->locale ?: 'am'), $user);

        return response()->json(['data' => $this->present($user)], 201);
    }

    /** DELETE /team/{id} — access ends at once: their sessions are signed out. */
    public function destroy(Request $request, int $member): Response
    {
        $user = $request->user()->team()->whereNull('removed_at')->findOrFail($member);
        $user->forceFill(['removed_at' => now()])->save();
        $user->tokens()->delete();

        return response()->noContent();
    }

    private function present(User $u): array
    {
        return ['id' => $u->id, 'name' => $u->name, 'phone' => $u->phone, 'joined' => $u->phone_verified_at !== null];
    }
}
