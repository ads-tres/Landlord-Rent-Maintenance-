<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Lease;
use App\Models\MaintenanceRequest;
use App\Services\Repairs\RepairService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The tenant's repair page, opened from an SMS link: /fix/{token}. No login.
 * Shows a short form and the status of the tenant's recent requests.
 * The link only works while the lease is active.
 */
class RepairPageController extends Controller
{
    public function __construct(private readonly RepairService $repairs) {}

    public function show(string $token): View
    {
        $lease = $this->lease($token);

        $recent = MaintenanceRequest::query()
            ->withoutGlobalScopes()
            ->where('lease_id', $lease->id)
            ->latest('opened_at')
            ->limit(5)
            ->get();

        return view('repairs.public', [
            'token' => $token,
            'lease' => $lease,
            'recent' => $recent,
            'categories' => MaintenanceRequest::CATEGORIES,
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $lease = $this->lease($token);

        $data = $request->validate([
            'category' => ['required', Rule::in(MaintenanceRequest::CATEGORIES)],
            'description' => ['required', 'string', 'min:3', 'max:1000'],
            'photo' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp,heic', 'max:8192'],
            'website' => ['prohibited'], // honeypot
        ]);

        $this->repairs->open(
            $lease->unit,
            [
                'title' => __('repairs.categories.'.$data['category'], [], $lease->landlord->locale ?: 'am'),
                'category' => $data['category'],
                'description' => $data['description'],
            ],
            'tenant',
            $lease,
            array_filter([$request->file('photo')]),
        );

        return redirect()->route('repairs.public.show', $token)->with('sent', true);
    }

    private function lease(string $token): Lease
    {
        $lease = Lease::query()
            ->withoutGlobalScopes()
            ->with([
                'unit' => fn ($q) => $q->withoutGlobalScopes()->with(['property' => fn ($p) => $p->withoutGlobalScopes()]),
                'tenant' => fn ($q) => $q->withoutGlobalScopes(),
                'landlord',
            ])
            ->where('repair_token', $token)
            ->first();

        abort_if(! $lease || $lease->status !== Lease::STATUS_ACTIVE, 404);

        return $lease;
    }
}
