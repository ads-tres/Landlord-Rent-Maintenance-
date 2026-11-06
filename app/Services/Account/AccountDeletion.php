<?php

namespace App\Services\Account;

use App\Models\Attachment;
use App\Models\SmsMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Deletes a landlord's account and everything in it, after the 30-day grace period. */
class AccountDeletion
{
    public const GRACE_DAYS = 30;

    public function purge(User $landlord): void
    {
        DB::transaction(function () use ($landlord) {
            // Caretakers only exist to work on this landlord's data.
            User::query()->where('landlord_id', $landlord->id)->get()->each(function (User $c) {
                $c->tokens()->delete();
                $c->delete();
            });
            $landlord->tokens()->delete();
            // The message log holds tenants' phone numbers; don't leave it behind.
            SmsMessage::query()->withoutGlobalScopes()->where('landlord_id', $landlord->id)->delete();
            $landlord->delete(); // landlord_id foreign keys cascade to the rest
        });
        Storage::disk(Attachment::DISK)->deleteDirectory("attachments/{$landlord->id}");
    }
}
