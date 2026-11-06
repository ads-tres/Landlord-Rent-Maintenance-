{{-- Shared deposit statement for the tenant web page and the PDF. $s comes from MoveOutStatement::for(). --}}
@php($l = $s['locale'])
<div class="receipt">
    <div class="brand">{{ config('app.name') }}</div>
    <div class="muted">{{ __('move_out.statement.title', [], $l) }}</div>

    <table class="details">
        <tr><th>{{ __('move_out.statement.tenant', [], $l) }}</th><td>{{ $s['tenant'] }}</td></tr>
        <tr><th>{{ __('move_out.statement.unit', [], $l) }}</th><td>{{ $s['place'] }}</td></tr>
        <tr><th>{{ __('move_out.statement.lease', [], $l) }}</th><td>{{ $s['lease_dates'] }}</td></tr>
        <tr><th>{{ __('move_out.statement.moved_out', [], $l) }}</th><td>{{ $s['moved_out_on'] }}</td></tr>
        <tr><th>{{ __('move_out.statement.keys', [], $l) }}</th><td>{{ $s['keys_returned'] ? __('move_out.statement.yes', [], $l) : __('move_out.statement.no', [], $l) }}</td></tr>
        <tr><th>{{ __('move_out.statement.landlord', [], $l) }}</th><td>{{ $s['landlord'] }}</td></tr>
    </table>

    <table class="lines">
        <tr><td class="strong">{{ __('move_out.statement.deposit', [], $l) }}</td><td class="right strong">{{ $s['deposit'] }}</td></tr>
        @forelse ($s['lines'] as $line)
            <tr>
                <td>{{ $line['kind'] }}@if ($line['description'])<br><span class="muted">{{ $line['description'] }}</span>@endif</td>
                <td class="right">− {{ $line['amount'] }}</td>
            </tr>
        @empty
            <tr><td class="muted">{{ __('move_out.statement.no_deductions', [], $l) }}</td><td></td></tr>
        @endforelse
        <tr class="total"><td>{{ __('move_out.statement.refund', [], $l) }}</td><td class="right">{{ $s['refund'] }}</td></tr>
    </table>

    @if ($s['refund_raw'] > 0 && $s['refund_method'])
        <p class="muted">{{ __('move_out.statement.refunded_by', ['method' => $s['refund_method']], $l) }}@if ($s['refund_reference']) · {{ $s['refund_reference'] }}@endif</p>
    @endif
    @if ($s['balance_raw'] > 0)
        <div class="note">{{ __('move_out.statement.balance', ['amount' => $s['balance']], $l) }}</div>
    @endif
    @if ($s['condition_notes'])
        <p><span class="strong">{{ __('move_out.statement.condition', [], $l) }}</span><br>{!! nl2br(e($s['condition_notes'])) !!}</p>
    @endif
    @if ($s['settled_on'])
        <p class="muted">{{ __('move_out.statement.settled_on', ['date' => $s['settled_on']], $l) }}</p>
    @endif
</div>
