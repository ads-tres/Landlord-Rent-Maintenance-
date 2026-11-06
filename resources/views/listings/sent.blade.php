@extends('listings.layout')
@section('title', __('listings.sent_title'))
@section('content')
    <div class="card">
        <div class="ok">{{ __('listings.sent_title') }}</div>
        <p>{{ __('listings.sent_body') }}</p>
    </div>
@endsection
