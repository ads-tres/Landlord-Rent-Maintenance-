@extends('admin.layout')
@section('content')
<h1>{{ __('admin.nav.waitlist') }}</h1>
<form class="card row" method="GET">
    <label>{{ __('admin.waitlist.min_units') }}<br><input name="min_units" type="number" min="1" value="{{ $filters['min_units'] }}" style="width:90px"></label>
    <label>{{ __('admin.waitlist.status') }}<br><select name="status"><option value="">{{ __('admin.waitlist.any') }}</option>@foreach (__('admin.waitlist.statuses') as $k => $v)<option value="{{ $k }}" @selected(($filters['status'] ?? '') === $k)>{{ $v }}</option>@endforeach</select></label>
    <label>{{ __('admin.waitlist.search') }}<br><input name="q" value="{{ $filters['q'] ?? '' }}"></label>
    <button class="btn">{{ __('admin.waitlist.filter') }}</button>
    <a class="btn light" href="{{ route('admin.waitlist.export', request()->query()) }}">{{ __('admin.waitlist.export') }}</a>
</form>

<div class="card">
@if ($signups->isEmpty())
    <p class="muted">{{ __('admin.waitlist.empty') }}</p>
@else
<table>
    <tr>@foreach (__('admin.waitlist.columns') as $c)<th>{{ $c }}</th>@endforeach<th>{{ __('admin.waitlist.status') }} / {{ __('admin.waitlist.notes') }}</th><th></th></tr>
    @foreach ($signups as $s)
    <tr>
        <td>{{ $s->phone }}<br><span class="muted">{{ $s->name }}</span></td>
        <td><strong>{{ $s->unit_count }}</strong></td>
        <td>{{ $s->current_tracking }}</td>
        <td style="max-width:260px">{{ $s->biggest_problem }}</td>
        <td>{{ $s->monthly_price_birr }}</td>
        <td>{{ $s->source }}</td>
        <td>{{ $s->created_at->toDateString() }}</td>
        <td style="min-width:220px">
            <form method="POST" action="{{ route('admin.waitlist.update', $s) }}">
                @csrf @method('PATCH')
                <select name="status">@foreach (__('admin.waitlist.statuses') as $k => $v)<option value="{{ $k }}" @selected($s->status === $k)>{{ $v }}</option>@endforeach</select>
                <textarea name="admin_notes" placeholder="{{ __('admin.waitlist.notes') }}">{{ $s->admin_notes }}</textarea>
                <button class="btn light">{{ __('admin.waitlist.save') }}</button>
            </form>
        </td>
        <td>
            @if ($s->landlord_id)
                <a href="{{ route('admin.landlords.show', $s->landlord_id) }}">→</a>
            @else
                <form method="POST" action="{{ route('admin.landlords.store') }}">
                    @csrf
                    <input type="hidden" name="phone" value="{{ $s->phone }}">
                    <input type="hidden" name="name" value="{{ $s->name }}">
                    <input type="hidden" name="locale" value="{{ $s->locale }}">
                    <input type="hidden" name="signup_id" value="{{ $s->id }}">
                    <button class="btn">{{ __('admin.waitlist.make_pilot') }}</button>
                </form>
            @endif
        </td>
    </tr>
    @endforeach
</table>
{{ $signups->links() }}
@endif
</div>
@endsection
