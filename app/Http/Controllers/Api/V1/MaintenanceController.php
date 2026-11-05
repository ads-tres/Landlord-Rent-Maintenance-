<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreMaintenanceRequest;
use App\Http\Requests\Api\UpdateMaintenanceRequest;
use App\Http\Resources\MaintenanceRequestResource;
use App\Models\MaintenanceRequest;
use App\Models\Unit;
use App\Services\Repairs\PhotoStore;
use App\Services\Repairs\RepairService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class MaintenanceController extends Controller
{
    public function __construct(private readonly RepairService $repairs) {}

    /** GET /maintenance?status=open|in_progress|done|active&unit_id= ("active" = not done) */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['sometimes', Rule::in([...MaintenanceRequest::STATUSES, 'active'])],
            'unit_id' => ['sometimes', 'integer'],
        ]);

        $items = MaintenanceRequest::query()
            ->with(['unit.property', 'tenant'])
            ->withCount('attachments')
            ->when($request->input('status'), function (Builder $q, string $status) {
                $status === 'active' ? $q->where('status', '!=', 'done') : $q->where('status', $status);
            })
            ->when($request->input('unit_id'), fn (Builder $q, $id) => $q->where('unit_id', $id))
            ->orderByRaw("case status when 'open' then 0 when 'in_progress' then 1 else 2 end")
            ->orderByDesc('opened_at')
            ->limit(200)
            ->get();

        return MaintenanceRequestResource::collection($items);
    }

    /** POST /maintenance (multipart) — the landlord records a repair, with up to 4 photos. */
    public function store(StoreMaintenanceRequest $request): MaintenanceRequestResource
    {
        $unit = Unit::query()->findOrFail($request->validated('unit_id'));

        $item = $this->repairs->open($unit, $request->validated(), 'landlord', photos: $request->file('photos', []));

        return new MaintenanceRequestResource($this->loadDetail($item));
    }

    public function show(MaintenanceRequest $maintenance): MaintenanceRequestResource
    {
        Gate::authorize('view', $maintenance);

        return new MaintenanceRequestResource($this->loadDetail($maintenance));
    }

    /** PATCH /maintenance/{id} — change status (SMS to the tenant by default) or details. */
    public function update(UpdateMaintenanceRequest $request, MaintenanceRequest $maintenance): MaintenanceRequestResource
    {
        Gate::authorize('update', $maintenance);
        $data = $request->validated();

        $maintenance->fill(array_intersect_key($data, array_flip(['title', 'category', 'description'])))->save();

        if (isset($data['status']) || array_key_exists('note', $data)) {
            $this->repairs->updateStatus(
                $maintenance,
                $data['status'] ?? $maintenance->status,
                $data['note'] ?? null,
                $request->boolean('notify_tenant', true),
                $request->user()->locale,
            );
        }

        return new MaintenanceRequestResource($this->loadDetail($maintenance));
    }

    /** POST /maintenance/{id}/photos (multipart, field "photo") */
    public function addPhoto(Request $request, MaintenanceRequest $maintenance, PhotoStore $photos): MaintenanceRequestResource
    {
        Gate::authorize('update', $maintenance);
        $request->validate(['photo' => ['required', 'image', 'mimes:jpeg,jpg,png,webp,heic', 'max:8192']]);
        abort_if($maintenance->attachments()->count() >= 8, 422, __('repairs.too_many_photos'));

        $photos->store($request->file('photo'), $maintenance, $maintenance->landlord_id);

        return new MaintenanceRequestResource($this->loadDetail($maintenance));
    }

    private function loadDetail(MaintenanceRequest $item): MaintenanceRequest
    {
        return $item->load(['unit.property', 'tenant', 'attachments']);
    }
}
