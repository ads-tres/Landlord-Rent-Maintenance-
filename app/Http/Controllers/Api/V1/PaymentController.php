<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StorePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\SmsMessage;
use App\Services\Payments\PaymentRecorder;
use App\Services\Payments\ReceiptData;
use App\Services\Sms\SmsNotifier;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentRecorder $recorder,
        private readonly SmsNotifier $sms,
    ) {}

    /** GET /payments?lease_id=&tenant_id=&from=&to= */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'lease_id' => ['sometimes', 'integer'],
            'tenant_id' => ['sometimes', 'integer'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        $payments = Payment::query()
            ->with(['tenant', 'allocations.charge'])
            ->when($request->input('lease_id'), fn (Builder $q, $id) => $q->where('lease_id', $id))
            ->when($request->input('tenant_id'), fn (Builder $q, $id) => $q->where('tenant_id', $id))
            ->when($request->input('from'), fn (Builder $q, $d) => $q->whereDate('paid_at', '>=', $d))
            ->when($request->input('to'), fn (Builder $q, $d) => $q->whereDate('paid_at', '<=', $d))
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->paginate(50);

        return PaymentResource::collection($payments);
    }

    /**
     * POST /payments — logs a payment, allocates it to charges (oldest first, then
     * months ahead) and, unless send_sms is false, texts the tenant a receipt link.
     */
    public function store(StorePaymentRequest $request): PaymentResource
    {
        $data = $request->validated();
        $lease = Lease::query()->with(['tenant', 'unit'])->findOrFail($data['lease_id']);

        $payment = $this->recorder->record($lease, $data);

        if ($request->boolean('send_sms', true)) {
            $message = $this->queueReceiptSms($payment, $request->user()->locale);
            $payment->setAttribute('sms_status', $message->fresh()->status);
        }

        return new PaymentResource($payment->load(['tenant', 'allocations.charge']));
    }

    public function show(Payment $payment): PaymentResource
    {
        Gate::authorize('view', $payment);

        return new PaymentResource($payment->load(['tenant', 'allocations.charge']));
    }

    /** DELETE /payments/{id} — remove a payment logged by mistake. */
    public function destroy(Payment $payment): Response
    {
        Gate::authorize('delete', $payment);
        abort_if($payment->source !== 'manual', 409, __('payments.cannot_void_online'));

        $this->recorder->void($payment);

        return response()->noContent();
    }

    /** GET /payments/{id}/receipt — receipt PDF the landlord can share. */
    public function receipt(Request $request, Payment $payment): SymfonyResponse
    {
        Gate::authorize('view', $payment);

        $data = ReceiptData::for($payment, app()->getLocale());

        return Pdf::loadView('receipts.pdf', ['r' => $data])
            ->setPaper('a5')
            ->download($payment->receiptNumber().'.pdf');
    }

    private function queueReceiptSms(Payment $payment, string $locale): SmsMessage
    {
        $payment->loadMissing(['tenant', 'lease.unit', 'allocations.charge']);
        $periods = $payment->allocations->map(fn ($a) => $a->charge->period)->sort()->values();

        $body = __('sms.receipt', [
            'amount' => ReceiptData::birr($payment->amount, $locale),
            'unit' => $payment->lease?->unit?->label ?? '',
            'months' => $periods->count() > 1
                ? ReceiptData::period($periods->first(), $locale).' – '.ReceiptData::period($periods->last(), $locale)
                : ReceiptData::period($periods->first(), $locale),
            'url' => $payment->receiptUrl(),
            'app' => config('app.name'),
        ], $locale);

        return $this->sms->queue($payment->landlord_id, $payment->tenant->phone, SmsMessage::KIND_RECEIPT, $body, $payment);
    }
}
