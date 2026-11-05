<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /api/v1/attachments/{id}/signed?expires=…&signature=… — a short-lived link for
 * players that can't send the login token (e.g. voice notes opened in another app).
 */
class SignedAttachmentController extends Controller
{
    public function __invoke(int $attachment): StreamedResponse
    {
        $file = Attachment::query()->withoutGlobalScopes()->findOrFail($attachment);
        abort_unless(Storage::disk(Attachment::DISK)->exists($file->path), 404);

        return Storage::disk(Attachment::DISK)->response($file->path, null, ['Content-Type' => $file->mime]);
    }
}
