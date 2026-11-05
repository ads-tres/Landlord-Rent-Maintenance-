@extends('admin.layout')
@section('title', __('admin.login.title'))
@section('content')
<div class="card" style="max-width:420px;margin:40px auto">
    <h1>{{ __('admin.login.title') }}</h1>
    @if ($step === 'phone')
        <form method="POST" action="{{ route('admin.login.send') }}">
            @csrf
            <label>{{ __('admin.login.phone') }}<br><input name="phone" type="tel" value="{{ old('phone') }}" required autofocus style="width:100%"></label>
            <p><button class="btn">{{ __('admin.login.send') }}</button></p>
        </form>
    @else
        <p class="muted">{{ __('admin.login.sent', ['phone' => $phone]) }}</p>
        <form method="POST" action="{{ route('admin.login.verify') }}">
            @csrf
            <input type="hidden" name="phone" value="{{ $phone }}">
            <label>{{ __('admin.login.code') }}<br><input name="code" inputmode="numeric" maxlength="6" required autofocus autocomplete="one-time-code" style="width:100%"></label>
            <p><button class="btn">{{ __('admin.login.verify') }}</button></p>
        </form>
    @endif
</div>
@endsection
