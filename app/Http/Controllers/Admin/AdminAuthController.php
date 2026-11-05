<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\EthiopianPhone;
use App\Services\Auth\OtpService;
use App\Support\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/** Admin login with an SMS code, like the app. Only users with the admin role get in. */
class AdminAuthController extends Controller
{
    public function __construct(private readonly OtpService $otp) {}

    public function show(): View
    {
        return view('admin.login', ['step' => 'phone']);
    }

    public function sendCode(Request $request): RedirectResponse
    {
        if (is_string($request->input('phone')) && ($p = PhoneNumber::normalize($request->input('phone')))) {
            $request->merge(['phone' => $p]);
        }
        $phone = $request->validate(['phone' => ['required', new EthiopianPhone]])['phone'];

        // Same response whether or not the number is an admin, so numbers can't be probed.
        if (User::query()->where('phone', $phone)->where('role', User::ROLE_ADMIN)->exists()) {
            $this->otp->send($phone, 'en');
        }

        return redirect()->route('admin.login.code')->with('phone', $phone);
    }

    public function showCode(Request $request): View|RedirectResponse
    {
        $phone = $request->session()->get('phone') ?? old('phone');

        return $phone ? view('admin.login', ['step' => 'code', 'phone' => $phone]) : redirect()->route('admin.login');
    }

    public function verify(Request $request): RedirectResponse
    {
        $data = $request->validate(['phone' => ['required', 'string'], 'code' => ['required', 'digits:6']]);
        $admin = User::query()->where('phone', $data['phone'])->where('role', User::ROLE_ADMIN)->first();

        if (! $admin || ! $this->otp->verify($data['phone'], $data['code'])) {
            return redirect()->route('admin.login.code')
                ->with('phone', $data['phone'])
                ->withErrors(['code' => __('auth.otp_invalid')]);
        }

        Auth::login($admin);
        $request->session()->regenerate();

        return redirect()->intended(route('admin.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
