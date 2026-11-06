<?php

namespace App\Http\Resources;

use App\Models\Listing;
use App\Services\Listings\ListingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * Amounts in santim.
 *
 * @mixin Listing
 */
class ListingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $detail = $this->relationLoaded('attachments');
        $service = app(ListingService::class);

        return [
            'id' => $this->id,
            'unit_id' => $this->unit_id,
            'unit_label' => $this->whenLoaded('unit', fn () => $this->unit->label),
            'property_name' => $this->whenLoaded('unit', fn () => $this->unit->property?->name),
            'asking_rent' => $this->asking_rent,
            'available_from' => $this->available_from->toDateString(),
            'description' => $this->description,
            'status' => $this->isLive() || $this->status !== Listing::STATUS_LIVE ? $this->status : Listing::STATUS_CLOSED,
            'url' => $this->url(),
            'published_at' => $this->published_at,
            'expires_at' => $this->expires_at,
            'report_count' => $this->report_count,
            'applications_count' => $this->whenCounted('applications'),
            'rent_check' => $this->when($detail, fn () => $service->rentCheck($this->resource)),
            'share_text' => $this->when($detail, fn () => $service->shareText($this->resource)),
            'photos' => $this->when($detail, fn () => $this->attachments->map(fn ($a) => [
                'id' => $a->id,
                'url' => url("/api/v1/attachments/{$a->id}"),
            ])),
        ];
    }

    /** Public page photo links (no login), valid for a day. */
    public static function publicPhotoUrls(Listing $listing): array
    {
        return $listing->attachments()->withoutGlobalScopes()->get()
            ->map(fn ($a) => URL::temporarySignedRoute('attachments.signed', now()->addDay(), ['attachment' => $a->id]))
            ->all();
    }
}
