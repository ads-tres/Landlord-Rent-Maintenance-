@extends('pay.layout')
@section('title', __('chapa.page_title'))
@section('content')
    <div class="card">
        <h1>{{ __('chapa.page_title') }}</h1>
        <div class="muted">{{ $lease->unit->label }}@if ($lease->unit->property) · {{ $lease->unit->property->name }}@endif</div>
    </div>
    <div class="card">
        @if ($balance > 0)
            <div class="muted">{{ __('chapa.balance') }}</div>
            <div class="big">{{ $balanceText }}</div>
            @if ($nextDue)<div class="muted">{{ __('chapa.due', ['date' => $nextDue]) }}</div>@endif
            <form method="POST" action="{{ route('pay.start', $token) }}">
                @csrf <input type="hidden" name="amount" value="{{ $balance }}">
                <button class="btn">{{ __('chapa.pay_amount', ['amount' => $balanceText]) }}</button>
            </form>
        @else
            <div class="ok">{{ __('chapa.nothing_due') }}</div>
        @endif
        @if ($balance !== $monthly)
            <form method="POST" action="{{ route('pay.start', $token) }}">
                @csrf <input type="hidden" name="amount" value="{{ $monthly }}">
                <button class="btn light">{{ __('chapa.pay_month', ['amount' => $monthlyText]) }}</button>
            </form>
        @endif
        @error('amount')<p class="error">{{ $message }}</p>@enderror
        <p class="muted">{{ __('chapa.how') }}</p>
    </div>
@endsection
