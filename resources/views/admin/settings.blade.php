@extends('admin.layout')
@section('content')
<h1>{{ __('admin.nav.settings') }}</h1>
<form class="card" method="POST" action="{{ route('admin.settings.update') }}" style="max-width:520px">
    @csrf @method('PUT')
    <label>{{ __('admin.settings.rent_cap') }}<br><input name="rent_cap_percent" type="number" step="0.01" min="0" max="100" value="{{ $cap }}" required></label>
    <p class="muted">{{ __('admin.settings.rent_cap_help') }}</p>
    <label>{{ __('admin.settings.rent_notice') }}<br><input name="rent_notice_days" type="number" step="1" min="0" max="365" value="{{ $notice }}" required></label>
    <p class="muted">{{ __('admin.settings.rent_notice_help') }}</p>
    <button class="btn">{{ __('admin.settings.save') }}</button>
</form>
@endsection
