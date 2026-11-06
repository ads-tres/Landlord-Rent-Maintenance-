@extends('listings.layout')
@section('title', __('listings.verify_title'))
@section('content')
    <div class="card">
        <h1>{{ __('listings.verify_title') }}</h1>
        <p class="muted">{{ __('listings.verify_sent', ['phone' => $phone]) }}</p>
        <form method="POST" action="{{ route('listings.public.verify.submit', $listing->slug) }}">
            @csrf
            <input type="hidden" name="phone" value="{{ $phone }}">
            <label for="code">{{ __('listings.code') }}</label>
            <input id="code" name="code" inputmode="numeric" maxlength="6" required autocomplete="one-time-code">
            @error('code')<div class="error">{{ $message }}</div>@enderror
            <button class="btn">{{ __('listings.confirm') }}</button>
        </form>
    </div>
@endsection
