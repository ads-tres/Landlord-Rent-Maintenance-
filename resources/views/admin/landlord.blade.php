@extends('admin.layout')
@section('content')
<h1>{{ $landlord->name ?: $landlord->phone }} @if ($landlord->is_pilot)<span class="pill">{{ __('admin.dashboard.pilot') }}</span>@endif</h1>
<p class="muted">{{ $landlord->phone }} · {{ $landlord->locale }} · {{ $landlord->calendar_pref }}</p>

@if ($row)
<div class="grid">
    <div class="card stat"><div class="v">{{ $row['occupied'] }}/{{ $row['units'] }}</div><div class="l">{{ __('admin.dashboard.table.units') }}</div></div>
    <div class="card stat"><div class="v">{{ $row['active_leases'] }}</div><div class="l">{{ __('admin.dashboard.table.leases') }}</div></div>
    <div class="card stat"><div class="v">{{ $row['on_time']['percent'] !== null ? $row['on_time']['percent'].'%' : '—' }}</div><div class="l">{{ __('admin.dashboard.table.on_time') }}</div></div>
    <div class="card stat"><div class="v">{{ $row['payments_this_month'] }}</div><div class="l">{{ __('admin.dashboard.table.payments') }}</div></div>
    <div class="card stat"><div class="v">{{ $row['sms_this_month'] }}</div><div class="l">{{ __('admin.dashboard.sms') }}</div></div>
</div>
@endif

<div class="card">
    <h2>{{ __('admin.landlord.activity') }}</h2>
    <table><tr>@foreach (array_reverse($weeks) as $w)<th>{{ $w['week'] }}</th>@endforeach</tr><tr>@foreach (array_reverse($weeks) as $w)<td>{{ $w['active'] }}/7</td>@endforeach</tr></table>
</div>

<div class="card">
    <h2>{{ __('admin.landlord.import_title') }}</h2>
    <p class="muted">{{ __('admin.landlord.import_help') }}</p>
    <p><a href="{{ route('admin.import.template') }}">{{ __('admin.landlord.template') }}</a> · <code>{{ implode(', ', $columns) }}</code></p>
    <form method="POST" action="{{ route('admin.landlords.import.preview', $landlord) }}" enctype="multipart/form-data" class="row">
        @csrf
        <input type="file" name="file" accept=".csv,text/csv" required>
        <button class="btn">{{ __('admin.landlord.upload') }}</button>
    </form>
</div>
@endsection
