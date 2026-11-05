@extends('admin.layout')
@section('content')
<h1>{{ __('admin.import.title') }} · {{ $landlord->name ?: $landlord->phone }}</h1>
<div class="card">
    @if ($rowErrors)
        <p class="err">{{ __('admin.import.has_errors') }}</p>
        @foreach ($rowErrors as $line => $messages)
            @if ($line === 0) @foreach ($messages as $m)<div class="err">{{ $m }}</div>@endforeach @endif
        @endforeach
    @else
        <p>{{ __('admin.import.ok', ['count' => count($rows)]) }}</p>
    @endif
    @if ($rows)
    <table>
        <tr><th>#</th><th>Property</th><th>Unit</th><th>Tenant</th><th>Rent (Br)</th><th>Lease</th><th>Paid through</th><th></th></tr>
        @foreach ($rows as $r)
        <tr>
            <td>{{ $r['line'] }}</td>
            <td>{{ $r['property'] }}</td>
            <td>{{ $r['unit'] }}</td>
            <td>@if ($r['tenant_name']){{ $r['tenant_name'] }}<br><span class="muted">{{ $r['tenant_phone'] }}</span>@else<span class="muted">{{ __('admin.import.vacant') }}</span>@endif</td>
            <td>{{ $r['monthly_rent'] !== null ? number_format($r['monthly_rent'] / 100) : '' }}</td>
            <td>{{ $r['lease_start'] }}@if ($r['lease_end']) → {{ $r['lease_end'] }}@endif</td>
            <td>{{ $r['paid_through'] }}</td>
            <td class="err">@foreach ($rowErrors[$r['line']] ?? [] as $m)<div>{{ $m }}</div>@endforeach</td>
        </tr>
        @endforeach
    </table>
    @endif
</div>
<div class="row">
    <a class="btn light" href="{{ route('admin.landlords.show', $landlord) }}">{{ __('admin.import.back') }}</a>
    @if (! $rowErrors && $rows)
        <form method="POST" action="{{ route('admin.landlords.import.confirm', $landlord) }}">@csrf <button class="btn">{{ __('admin.import.confirm', ['count' => count($rows)]) }}</button></form>
    @endif
</div>
@endsection
