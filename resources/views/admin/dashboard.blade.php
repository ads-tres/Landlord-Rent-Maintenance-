@extends('admin.layout')
@section('content')
@php($o = $overview)
<div class="grid">
    <div class="card stat"><div class="v">{{ $o['pilots'] }} / {{ $o['landlords'] }}</div><div class="l">{{ __('admin.dashboard.pilots') }} / {{ __('admin.dashboard.landlords') }}</div></div>
    <div class="card stat"><div class="v">{{ $o['weekly_active'][0]['active'] }}</div><div class="l">{{ __('admin.dashboard.weekly_active') }}</div></div>
    <div class="card stat"><div class="v">{{ $o['on_time']['percent'] !== null ? $o['on_time']['percent'].'%' : '—' }}</div><div class="l">{{ __('admin.dashboard.on_time') }} ({{ $o['on_time']['on_time'] }}/{{ $o['on_time']['due'] }})</div></div>
    <div class="card stat"><div class="v">{{ $o['sms_this_month'] }}</div><div class="l">{{ __('admin.dashboard.sms') }}@if ($o['sms_failed_this_month']) · <span class="err">{{ __('admin.dashboard.sms_failed', ['count' => $o['sms_failed_this_month']]) }}</span>@endif</div></div>
    <div class="card stat"><div class="v">{{ $o['online_payments'] }}</div><div class="l">{{ __('admin.dashboard.online', ['amount' => number_format($o['online_amount'] / 100)]) }}</div></div>
    <div class="card stat"><div class="v">{{ $o['waitlist'] }}</div><div class="l">{{ __('admin.dashboard.waitlist') }} · {{ __('admin.dashboard.waitlist_3plus', ['count' => $o['waitlist_3plus']]) }}</div></div>
</div>

<div class="card">
    <h2>{{ __('admin.dashboard.weeks') }}</h2>
    <table><tr>@foreach (array_reverse($o['weekly_active']) as $w)<th>{{ __('admin.dashboard.week_of', ['date' => $w['week']]) }}</th>@endforeach</tr>
    <tr>@foreach (array_reverse($o['weekly_active']) as $w)<td>{{ $w['active'] }}</td>@endforeach</tr></table>
</div>

<div class="card">
    <h2>{{ __('admin.dashboard.landlords') }}</h2>
    @if ($landlords->isEmpty())
        <p class="muted">{{ __('admin.dashboard.none') }}</p>
    @else
    <table>
        <tr>@foreach (__('admin.dashboard.table') as $label)<th>{{ $label }}</th>@endforeach</tr>
        @foreach ($landlords as $l)
        <tr>
            <td><a href="{{ route('admin.landlords.show', $l['user']) }}">{{ $l['user']->name ?: $l['user']->phone }}</a> @if ($l['user']->is_pilot)<span class="pill">{{ __('admin.dashboard.pilot') }}</span>@endif<br><span class="muted">{{ $l['user']->phone }}</span></td>
            <td>{{ $l['occupied'] }}/{{ $l['units'] }}</td>
            <td>{{ $l['tenants'] }}</td>
            <td>{{ $l['active_leases'] }}</td>
            <td>{{ $l['payments_this_month'] }}</td>
            <td>{{ $l['on_time']['percent'] !== null ? $l['on_time']['percent'].'%' : '—' }}</td>
            <td>{{ $l['open_repairs'] }}</td>
            <td>{{ $l['sms_this_month'] }}</td>
            <td>{{ $l['active_days_7'] }}</td>
            <td>{{ $l['last_active'] ?? __('admin.dashboard.never') }}</td>
        </tr>
        @endforeach
    </table>
    @endif
</div>

<div class="card">
    <h2>{{ __('admin.dashboard.add_landlord') }}</h2>
    <form method="POST" action="{{ route('admin.landlords.store') }}" class="row">
        @csrf
        <label>{{ __('admin.landlord.phone') }}<br><input name="phone" type="tel" required></label>
        <label>{{ __('admin.landlord.name') }}<br><input name="name"></label>
        <label>{{ __('admin.landlord.language') }}<br><select name="locale"><option value="am">አማርኛ</option><option value="en">English</option></select></label>
        <button class="btn">{{ __('admin.landlord.create') }}</button>
    </form>
</div>
@endsection
