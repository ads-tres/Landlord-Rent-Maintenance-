{{-- Shared receipt body for the tenant web page and the PDF. $r comes from ReceiptData::for(). --}}
<div class="receipt">
    <div class="head">
        <div>
            <div class="brand">{{ config('app.name') }}</div>
            <div class="muted">{{ __('payments.receipt.title', [], $r['locale']) }}</div>
        </div>
        <div class="right">
            <div class="strong">{{ $r['number'] }}</div>
            <div class="muted">{{ $r['paid_at'] }}</div>
        </div>
    </div>

    <div class="amount">{{ $r['amount'] }}</div>
    <div class="muted center">{{ __('payments.receipt.received_from', ['name' => $r['tenant_name']], $r['locale']) }}</div>

    <table class="details">
        @if ($r['unit'])
            <tr><th>{{ __('payments.receipt.unit', [], $r['locale']) }}</th><td>{{ $r['unit'] }}@if ($r['property']) · {{ $r['property'] }}@endif</td></tr>
        @endif
        <tr><th>{{ __('payments.receipt.method', [], $r['locale']) }}</th><td>{{ $r['method'] }}</td></tr>
        @if ($r['reference'])
            <tr><th>{{ __('payments.receipt.reference', [], $r['locale']) }}</th><td>{{ $r['reference'] }}</td></tr>
        @endif
        <tr><th>{{ __('payments.receipt.landlord', [], $r['locale']) }}</th><td>{{ $r['landlord_name'] }}</td></tr>
    </table>

    <table class="lines">
        <tr><th>{{ __('payments.receipt.covers', [], $r['locale']) }}</th><th class="right">{{ __('payments.receipt.amount', [], $r['locale']) }}</th></tr>
        @foreach ($r['lines'] as $line)
            <tr>
                <td>{{ $line['label'] }}@unless ($line['full']) <span class="muted">({{ __('payments.receipt.partial', [], $r['locale']) }})</span>@endunless</td>
                <td class="right">{{ $line['amount'] }}</td>
            </tr>
        @endforeach
        <tr class="total"><td>{{ __('payments.receipt.total', [], $r['locale']) }}</td><td class="right">{{ $r['amount'] }}</td></tr>
    </table>

    @if ($r['outstanding'])
        <p class="note">{{ __('payments.receipt.outstanding', ['amount' => $r['outstanding']], $r['locale']) }}</p>
    @endif

    <p class="footer muted">{{ __('payments.receipt.footer', ['app' => config('app.name')], $r['locale']) }}</p>
</div>
