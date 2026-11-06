<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\MoveOutResource;
use App\Models\Lease;
use App\Models\MoveOut;
use App\Models\MoveOutDeduction;
use App\Models\Payment;
use App\Services\MoveOut\MoveOutService;
use App\Services\MoveOut\MoveOutStatement;
use App\Services\Repairs\PhotoStore;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** Move-out: condition check, deposit deductions, settlement and the statement. */
class MoveOutController extends Controller
{
    public function __construct(private readonly MoveOutService $service) {}

    /** POST /leases/{id}/move-out — starts (or returns) the lease's move-out with its unpaid charges filled in. */
    public function start(Request $request, Lease $lease): MoveOutResource
    {
        Gate::authorize('update', $lease);
        $data = $request->validate($this->dateRules($lease, true) + $this->checkRules());

        return $this->resource($this->service->start($lease, $data));
    }

    public function show(MoveOut $moveOut): MoveOutResource
    {
        Gate::authorize('view', $moveOut);

        return $this->resource($moveOut);
    }

    public function update(Request $request, MoveOut $moveOut): MoveOutResource
    {
        Gate::authorize('update', $moveOut);
        $data = $request->validate($this->dateRules($moveOut->lease, false) + $this->checkRules());

        return $this->resource($this->service->update($moveOut, $data));
    }

    /** POST /move-outs/{id}/deductions {kind: damage|cleaning|keys|other, description, amount} */
    public function addDeduction(Request $request, MoveOut $moveOut): MoveOutResource
    {
        Gate::authorize('update', $moveOut);
        $data = $request->validate([
            'kind' => ['required', Rule::in(MoveOutDeduction::MANUAL_KINDS)],
            'description' => ['required', 'string', 'max:250'],
            'amount' => ['required', 'integer', 'min:1', 'max:10000000000'],
        ]);
        $this->service->addDeduction($moveOut, $data);

        return $this->resource($moveOut);
    }

    public function removeDeduction(MoveOutDeduction $deduction): MoveOutResource
    {
        Gate::authorize('delete', $deduction);
        $this->service->removeDeduction($deduction);

        return $this->resource($deduction->moveOut);
    }

    /** POST /move-outs/{id}/photos — condition photos (up to 12). */
    public function addPhoto(Request $request, MoveOut $moveOut, PhotoStore $photos): MoveOutResource
    {
        Gate::authorize('update', $moveOut);
        $request->validate(['photo' => ['required', 'image', 'mimes:jpeg,jpg,png,webp,heic', 'max:8192']]);
        abort_if($moveOut->attachments()->count() >= 12, 422, __('move_out.too_many_photos'));
        $photos->store($request->file('photo'), $moveOut, $moveOut->landlord_id);

        return $this->resource($moveOut);
    }

    /**
     * POST /move-outs/{id}/settle {refund_method?, refund_reference?, notify_tenant?}
     * Applies the deposit, ends the lease, frees the unit and (by default) sends the tenant the statement.
     */
    public function settle(Request $request, MoveOut $moveOut): MoveOutResource
    {
        Gate::authorize('update', $moveOut);
        $data = $request->validate([
            'refund_method' => ['nullable', Rule::in(Payment::MANUAL_METHODS)],
            'refund_reference' => ['nullable', 'string', 'max:120'],
            'notify_tenant' => ['sometimes', 'boolean'],
        ]);

        $this->service->settle($moveOut, $data, $request->boolean('notify_tenant', true), $request->user()->locale ?: 'am');

        return $this->resource($moveOut->refresh());
    }

    /** POST /move-outs/{id}/send — text the statement link to the tenant again. */
    public function send(Request $request, MoveOut $moveOut): Response
    {
        Gate::authorize('update', $moveOut);
        abort_unless($moveOut->isSettled(), 422, __('move_out.not_settled'));
        $this->service->notify($moveOut, $request->user()->locale ?: 'am');

        return response()->noContent();
    }

    /** GET /move-outs/{id}/statement — PDF to share. */
    public function statement(Request $request, MoveOut $moveOut): Response
    {
        Gate::authorize('view', $moveOut);
        $locale = app()->getLocale();

        return Pdf::loadView('move-outs.pdf', ['s' => MoveOutStatement::for($moveOut, $locale)])
            ->setPaper('a4')
            ->download(sprintf('deposit-statement-%d.pdf', $moveOut->id));
    }

    private function dateRules(Lease $lease, bool $required): array
    {
        return ['moved_out_on' => [
            $required ? 'required' : 'sometimes', 'date_format:Y-m-d',
            'after_or_equal:'.$lease->start_date->toDateString(),
            'before_or_equal:'.$lease->end_date->toDateString(),
        ]];
    }

    private function checkRules(): array
    {
        return [
            'condition_notes' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'keys_returned' => ['sometimes', 'boolean'],
        ];
    }

    private function resource(MoveOut $moveOut): MoveOutResource
    {
        return new MoveOutResource($moveOut->refresh()->load(['deductions', 'attachments', 'lease.tenant', 'lease.unit']));
    }
}
