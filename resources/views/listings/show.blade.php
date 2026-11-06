@extends('listings.layout')
@section('title', $listing->unit->label.' · '.$listing->unit->property->name)
@section('content')
    @if ($photos)
        <div class="photos">@foreach ($photos as $src)<img src="{{ $src }}" alt="" loading="lazy">@endforeach</div>
    @endif
    <div class="card">
        <h1>{{ $listing->unit->bedrooms !== null ? trans_choice('listings.bedrooms', $listing->unit->bedrooms, ['count' => $listing->unit->bedrooms]) : __('listings.home') }}
            · {{ collect([$listing->unit->property->sub_city, $listing->unit->property->woreda ? __('listings.woreda', ['w' => $listing->unit->property->woreda]) : null])->filter()->implode(', ') ?: $listing->unit->property->name }}</h1>
        <div class="rent">{{ $rent }} <span class="muted">/ {{ __('listings.month') }}</span></div>
        <div class="muted">{{ __('listings.available', ['date' => $available]) }}</div>
        @if ($listing->description)<p>{{ $listing->description }}</p>@endif
    </div>

    <div class="card">
        <strong>{{ __('listings.apply_title') }}</strong>
        <form method="POST" action="{{ route('listings.public.apply', $listing->slug) }}">
            @csrf
            <div class="hp" aria-hidden="true"><input name="website" tabindex="-1" autocomplete="off"></div>
            <label for="full_name">{{ __('listings.full_name') }}</label>
            <input id="full_name" name="full_name" value="{{ old('full_name') }}" required autocomplete="name">
            @error('full_name')<div class="error">{{ $message }}</div>@enderror
            <label for="phone">{{ __('listings.phone') }}</label>
            <input id="phone" name="phone" type="tel" value="{{ old('phone') }}" required placeholder="09XX XXX XXX" autocomplete="tel">
            @error('phone')<div class="error">{{ $message }}</div>@enderror
            <label for="household_size">{{ __('listings.household_size') }}</label>
            <input id="household_size" name="household_size" type="number" min="1" max="20" value="{{ old('household_size') }}">
            <label for="move_in_date">{{ __('listings.move_in') }}</label>
            <input id="move_in_date" name="move_in_date" type="date" value="{{ old('move_in_date') }}">
            @error('move_in_date')<div class="error">{{ $message }}</div>@enderror
            <label for="message">{{ __('listings.message') }}</label>
            <textarea id="message" name="message" maxlength="500">{{ old('message') }}</textarea>
            <label style="font-weight:400"><input type="checkbox" name="privacy" value="1" required> {{ __('listings.privacy') }}</label>
            @error('privacy')<div class="error">{{ $message }}</div>@enderror
            <button class="btn">{{ __('listings.apply') }}</button>
        </form>
    </div>

    @if (session('reported'))
        <p class="ok">{{ __('listings.reported') }}</p>
    @else
        <form method="POST" action="{{ route('listings.public.report', $listing->slug) }}" style="text-align:center">
            @csrf <button class="link">{{ __('listings.report') }}</button>
        </form>
    @endif
@endsection
