<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\LeaseResource;
use App\Models\Lease;
use App\Services\Leases\LeaseAgreement;
use App\Services\Repairs\PhotoStore;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** The written lease: the agreement PDF, extra terms, registration and photos of the signed copy. */
class LeaseAgreementController extends Controller
{
    /** GET /leases/{id}/agreement?language=am|en|both — defaults to the landlord's language. */
    public function show(Request $request, Lease $lease): Response
    {
        Gate::authorize('view', $lease);
        $language = $request->validate(['language' => ['sometimes', Rule::in(LeaseAgreement::LANGUAGES)]])['language']
            ?? ($request->user()->actingLandlord()->locale ?: 'am');

        $docs = $language === 'both'
            ? [LeaseAgreement::build($lease, 'am'), LeaseAgreement::build($lease, 'en')]
            : [LeaseAgreement::build($lease, $language)];

        return Pdf::loadView('agreements.lease', ['docs' => $docs])
            ->setPaper('a4')
            ->download(sprintf('lease-agreement-%d.pdf', $lease->id));
    }

    /** PUT /leases/{id}/extra-terms {extra_terms} — added to the agreement as its own clause. */
    public function terms(Request $request, Lease $lease): LeaseResource
    {
        Gate::authorize('update', $lease);
        $lease->update($request->validate(['extra_terms' => ['present', 'nullable', 'string', 'max:3000']]));

        return $this->fresh($lease);
    }

    /** PUT /leases/{id}/registration {registered_at, registration_number?, registration_office?} */
    public function register(Request $request, Lease $lease): LeaseResource
    {
        Gate::authorize('update', $lease);
        $lease->update($request->validate([
            'registered_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'registration_number' => ['nullable', 'string', 'max:60'],
            'registration_office' => ['nullable', 'string', 'max:120'],
        ]));

        return $this->fresh($lease);
    }

    /** DELETE /leases/{id}/registration — recorded by mistake. */
    public function unregister(Lease $lease): LeaseResource
    {
        Gate::authorize('update', $lease);
        $lease->update(['registered_at' => null, 'registration_number' => null, 'registration_office' => null]);

        return $this->fresh($lease);
    }

    /** POST /leases/{id}/documents {photo} — a page of the signed agreement (up to 10). */
    public function addDocument(Request $request, Lease $lease, PhotoStore $photos): LeaseResource
    {
        Gate::authorize('update', $lease);
        $request->validate(['photo' => ['required', 'image', 'mimes:jpeg,jpg,png,webp,heic', 'max:8192']]);
        abort_if($lease->attachments()->count() >= 10, 422, __('agreement.too_many_photos'));
        $photos->store($request->file('photo'), $lease, $lease->landlord_id);

        return $this->fresh($lease);
    }

    private function fresh(Lease $lease): LeaseResource
    {
        return app(LeaseController::class)->show($lease->refresh());
    }
}
