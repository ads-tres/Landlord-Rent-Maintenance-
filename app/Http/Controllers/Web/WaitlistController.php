<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\WaitlistSignup;
use App\Rules\EthiopianPhone;
use App\Support\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WaitlistController extends Controller
{
    public const TRACKING_OPTIONS = ['notebook', 'memory', 'spreadsheet', 'app', 'other'];

    public function show(Request $request): View
    {
        return view('landing', [
            'trackingOptions' => self::TRACKING_OPTIONS,
            'source' => $request->query('src', $request->query('utm_source')),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (is_string($request->input('phone')) && ($p = PhoneNumber::normalize($request->input('phone')))) {
            $request->merge(['phone' => $p]);
        }

        $data = $request->validate([
            'phone' => ['required', 'string', new EthiopianPhone],
            'name' => ['nullable', 'string', 'max:100'],
            'unit_count' => ['required', 'integer', 'min:1', 'max:500'],
            'current_tracking' => ['nullable', 'in:'.implode(',', self::TRACKING_OPTIONS)],
            'biggest_problem' => ['nullable', 'string', 'max:1000'],
            'monthly_price_birr' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'source' => ['nullable', 'string', 'max:100'],
            'website' => ['prohibited'], // honeypot
        ]);

        WaitlistSignup::query()->updateOrCreate(
            ['phone' => $data['phone']],
            [...$data, 'locale' => app()->getLocale()],
        );

        return redirect()->to(route('landing').'#join')->with('joined', true);
    }
}
