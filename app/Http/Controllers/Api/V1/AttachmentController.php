<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** GET /attachments/{id} — photos are private; only the owning landlord can load them. */
class AttachmentController extends Controller
{
    public function show(Attachment $attachment): StreamedResponse
    {
        // The BelongsToLandlord scope already 404s other landlords' files.
        abort_unless(Storage::disk(Attachment::DISK)->exists($attachment->path), 404);

        return Storage::disk(Attachment::DISK)->response($attachment->path, null, [
            'Content-Type' => $attachment->mime,
            'Cache-Control' => 'private, max-age=604800',
        ]);
    }
}
