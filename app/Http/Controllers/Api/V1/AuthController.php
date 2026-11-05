<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SendOtpRequest;
use App\Http\Requests\Api\UpdateProfileRequest;
use App\Http\Requests\Api\VerifyOtpRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly OtpService $otp) {}

    /** POST /auth/otp — send a login code by SMS. */
    public function sendOtp(SendOtpRequest $request): JsonResponse
    {
        $this->otp->send($request->validated('phone'), $request->validated('locale', 'am'));

        return response()->json([
            'message' => __('auth.otp_sent'),
            'expires_in' => (int) config('sms.otp.ttl_minutes') * 60,
        ], Response::HTTP_ACCEPTED);
    }

    /** POST /auth/verify — check the code and return a Sanctum token. */
    public function verify(VerifyOtpRequest $request): JsonResponse
    {
        $phone = $request->validated('phone');

        if (! $this->otp->verify($phone, $request->validated('code'))) {
            throw ValidationException::withMessages(['code' => __('auth.otp_invalid')]);
        }

        $user = User::query()->firstOrCreate(
            ['phone' => $phone],
            ['role' => User::ROLE_LANDLORD, 'locale' => $request->validated('locale', 'am')],
        );
        $user->forceFill(['phone_verified_at' => now()])->save();

        $token = $user->createToken($request->validated('device_name', 'mobile'))->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user),
            'is_new' => $user->wasRecentlyCreated,
        ]);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function updateMe(UpdateProfileRequest $request): UserResource
    {
        $request->user()->update($request->validated());

        return new UserResource($request->user());
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->noContent();
    }
}
