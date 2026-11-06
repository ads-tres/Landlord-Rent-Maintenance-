@extends('pay.layout')
@section('title', __('chapa.page_title'))
@section('content')
    <div class="card">
        @if ($status === 'success')
            <div class="ok">{{ __('chapa.paid') }}</div>
            @if ($receiptUrl)<a class="btn" href="{{ $receiptUrl }}">{{ __('chapa.see_receipt') }}</a>@endif
        @elseif ($status === 'failed')
            <div class="warn">{{ __('chapa.failed') }}</div>
            <a class="btn" href="{{ route('pay.show', $token) }}">{{ __('chapa.try_again') }}</a>
        @else
            <div class="warn">{{ __('chapa.pending') }}</div>
            <a class="btn light" href="{{ route('pay.done', ['token' => $token, 'tx_ref' => request('tx_ref')]) }}">{{ __('chapa.check_again') }}</a>
        @endif
    </div>
@endsection
