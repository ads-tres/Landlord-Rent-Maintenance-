<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Services\Notifications\AnnouncementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AnnouncementController extends Controller
{
    public function __construct(private readonly AnnouncementService $announcements) {}

    /** GET /properties/{id}/announcements */
    public function index(Property $property): JsonResponse
    {
        Gate::authorize('view', $property);

        return response()->json(['data' => $property->announcements()->latest()->limit(50)->get()
            ->map->only(['id', 'body', 'telegram_count', 'sms_count', 'sms_segments', 'created_at'])]);
    }

    /** POST /properties/{id}/announcements/preview — recipients and SMS cost, nothing sent. */
    public function preview(Request $request, Property $property): JsonResponse
    {
        Gate::authorize('update', $property);
        $body = $request->validate(['body' => ['required', 'string', 'max:300']])['body'];

        return response()->json(['data' => $this->announcements->preview($property, $body, $request->user())]);
    }

    /** POST /properties/{id}/announcements — sends it. */
    public function store(Request $request, Property $property): JsonResponse
    {
        Gate::authorize('update', $property);
        $body = $request->validate(['body' => ['required', 'string', 'max:300']])['body'];

        $announcement = $this->announcements->send($property, $body, $request->user());

        return response()->json(['data' => $announcement->only(['id', 'body', 'telegram_count', 'sms_count', 'sms_segments', 'created_at'])], 201);
    }
}
