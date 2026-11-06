<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\Account\AccountExport;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** The landlord's own data: download all of it, or ask for the account to be deleted. */
class AccountController extends Controller
{
    /** GET /account/export — a ZIP of CSV files. */
    public function export(Request $request, AccountExport $export): Response
    {
        $path = $export->write($request->user());

        return response()->download($path, sprintf('%s-data-%s.zip', str(config('app.name'))->slug(), now()->toDateString()), [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend();
    }

    /** POST /account/deletion {phone} — deleted after 30 days unless cancelled. */
    public function requestDeletion(Request $request): UserResource
    {
        $user = $request->user();
        $phone = preg_replace('/\D/', '', (string) $request->input('phone'));
        if ($phone === '' || ! str_ends_with(preg_replace('/\D/', '', $user->phone), ltrim($phone, '0'))) {
            throw ValidationException::withMessages(['phone' => __('account.phone_mismatch')]);
        }
        $user->forceFill(['deletion_requested_at' => now()])->save();

        return new UserResource($user);
    }

    /** DELETE /account/deletion — keep the account. */
    public function cancelDeletion(Request $request): UserResource
    {
        $request->user()->forceFill(['deletion_requested_at' => null])->save();

        return new UserResource($request->user());
    }
}
