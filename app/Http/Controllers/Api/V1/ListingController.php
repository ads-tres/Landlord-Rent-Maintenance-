<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApplicationResource;
use App\Http\Resources\ListingResource;
use App\Models\Application;
use App\Models\Listing;
use App\Models\Unit;
use App\Services\Listings\ListingService;
use App\Services\Repairs\PhotoStore;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ListingController extends Controller
{
    public function __construct(private readonly ListingService $listings) {}

    /** GET /listings — the landlord's listings, newest first. */
    public function index(): AnonymousResourceCollection
    {
        return ListingResource::collection(Listing::query()
            ->with('unit.property')
            ->withCount(['applications' => fn ($q) => $q->whereNotNull('phone_verified_at')])
            ->latest()
            ->limit(100)
            ->get());
    }

    /** POST /units/{id}/listing */
    public function store(Request $request, Unit $unit): ListingResource
    {
        Gate::authorize('update', $unit);
        $listing = $this->listings->create($unit, $this->validated($request, true));

        return new ListingResource($this->detail($listing));
    }

    public function show(Listing $listing): ListingResource
    {
        Gate::authorize('view', $listing);

        return new ListingResource($this->detail($listing));
    }

    /** PATCH /listings/{id} — edit, publish (status=live) or close (status=closed). */
    public function update(Request $request, Listing $listing): ListingResource
    {
        Gate::authorize('update', $listing);
        $data = $this->validated($request, false);
        $status = $request->validate(['status' => ['sometimes', Rule::in([Listing::STATUS_LIVE, Listing::STATUS_CLOSED])]])['status'] ?? null;

        $listing->update($data);
        match ($status) {
            Listing::STATUS_LIVE => $this->listings->publish($listing, $request->user()),
            Listing::STATUS_CLOSED => $this->listings->close($listing),
            default => null,
        };

        return new ListingResource($this->detail($listing->refresh()));
    }

    /** POST /listings/{id}/photos (multipart "photo", up to 6) */
    public function addPhoto(Request $request, Listing $listing, PhotoStore $photos): ListingResource
    {
        Gate::authorize('update', $listing);
        $request->validate(['photo' => ['required', 'image', 'max:8192']]);
        abort_if($listing->attachments()->count() >= 6, 422, __('listings.too_many_photos'));

        $photos->store($request->file('photo'), $listing, $listing->landlord_id);

        return new ListingResource($this->detail($listing));
    }

    /** GET /listings/{id}/applications — phone-verified applicants only. */
    public function applications(Listing $listing): AnonymousResourceCollection
    {
        Gate::authorize('view', $listing);

        return ApplicationResource::collection($listing->applications()
            ->whereNotNull('phone_verified_at')
            ->orderByRaw("case status when 'approved' then 0 when 'shortlisted' then 1 when 'new' then 2 else 3 end")
            ->latest()
            ->get());
    }

    /** PATCH /applications/{id} {status: shortlisted|rejected|approved|new} */
    public function updateApplication(Request $request, Application $application): ApplicationResource
    {
        Gate::authorize('update', $application);
        $status = $request->validate(['status' => ['required', Rule::in(Application::STATUSES)]])['status'];

        return new ApplicationResource($this->listings->setStatus($application, $status));
    }

    private function detail(Listing $listing): Listing
    {
        return $listing->load(['unit.property', 'attachments', 'landlord'])
            ->loadCount(['applications' => fn ($q) => $q->whereNotNull('phone_verified_at')]);
    }

    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'asking_rent' => [$required, 'integer', 'min:100', 'max:100000000'],
            'available_from' => [$required, 'date_format:Y-m-d'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);
    }
}
