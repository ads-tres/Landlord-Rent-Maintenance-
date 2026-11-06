<?php

namespace App\Services\Repairs;

use App\Models\Lease;
use App\Models\MaintenanceRequest;
use App\Models\SmsMessage;
use App\Models\Unit;
use App\Models\User;
use App\Services\Sms\SmsNotifier;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RepairService
{
    public function __construct(
        private readonly PhotoStore $photos,
        private readonly SmsNotifier $sms,
    ) {}

    /**
     * @param  array{title: string, category?: string, description?: ?string}  $data
     * @param  list<UploadedFile>  $photos
     */
    public function open(Unit $unit, array $data, string $source, ?Lease $lease = null, array $photos = []): MaintenanceRequest
    {
        return DB::transaction(function () use ($unit, $data, $source, $lease, $photos) {
            $lease ??= $unit->activeLease()->first();

            $request = new MaintenanceRequest([
                'unit_id' => $unit->id,
                'lease_id' => $lease?->id,
                'tenant_id' => $lease?->tenant_id,
                'title' => $data['title'],
                'category' => $data['category'] ?? 'other',
                'description' => $data['description'] ?? null,
                'status' => 'open',
                'source' => $source,
                'opened_at' => now(),
            ]);
            $request->client_ref = $data['client_ref'] ?? null;
            $request->landlord_id = $unit->landlord_id;
            $request->save();

            foreach ($photos as $photo) {
                $this->photos->store($photo, $request, $unit->landlord_id);
            }

            if ($source === 'tenant') {
                $this->alertLandlord($request, $unit);
            }

            return $request;
        });
    }

    /** Telegram alert to the landlord about a tenant's new repair (if they linked the bot). */
    public function alertLandlord(MaintenanceRequest $request, Unit $unit): void
    {
        $landlord = User::query()->find($unit->landlord_id);
        if (! $landlord) {
            return;
        }

        $this->sms->toLandlord($landlord, SmsMessage::KIND_NEW_REPAIR, __('telegram.new_repair', [
            'unit' => $unit->label,
            'title' => mb_strimwidth($request->title, 0, 60, '…'),
            'app' => config('app.name'),
        ], $landlord->locale ?: 'am'), $request);
    }

    /** Moves a request along open → in progress → done, optionally texting the tenant. */
    public function updateStatus(MaintenanceRequest $request, string $status, ?string $note, bool $notifyTenant, string $locale): MaintenanceRequest
    {
        $changed = $request->status !== $status;

        $request->fill([
            'status' => $status,
            'resolution_note' => $note ?? $request->resolution_note,
            'closed_at' => $status === 'done' ? ($request->closed_at ?? now()) : null,
        ]);
        if ($changed && Auth::id()) {
            $request->handled_by = Auth::id();
        }
        $request->save();

        if ($changed && $notifyTenant && $status !== 'open' && $request->tenant) {
            $request->loadMissing('unit');
            $body = __('sms.repair_'.$status, [
                'title' => mb_strimwidth($request->title, 0, 40, '…'),
                'unit' => $request->unit->label,
                'note' => $note ? ' '.mb_strimwidth($note, 0, 60, '…') : '',
                'app' => config('app.name'),
            ], $locale);

            $this->sms->toTenant($request->tenant, SmsMessage::KIND_REPAIR_UPDATE, $body, $request);
        }

        return $request;
    }
}
