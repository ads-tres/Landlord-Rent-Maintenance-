<!DOCTYPE html>
@php($l = $p['locale'])
<html lang="{{ $l }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('portal.title', [], $l) }} — {{ config('app.name') }}</title>
    {{-- The tenant's page, opened from an SMS on a basic phone: no scripts, no web fonts, small. --}}
    <style>
        body { margin:0; background:#f7f8f6; color:#1d2421; font-family:"Noto Sans Ethiopic","Nyala","Abyssinica SIL",system-ui,sans-serif; line-height:1.5; }
        .wrap { max-width:520px; margin:0 auto; padding:16px; }
        .card { background:#fff; border:1px solid #e1e5e2; border-radius:14px; padding:18px; margin-bottom:14px; }
        h1 { font-size:1.3rem; margin:0 0 4px; }
        h2 { font-size:1.05rem; margin:0 0 8px; }
        .brand { font-weight:700; color:#0f6e4f; }
        .muted { color:#5b6661; font-size:.9rem; }
        .big { font-size:1.8rem; font-weight:700; }
        .due { color:#b42318; }
        .row { display:flex; justify-content:space-between; gap:8px; padding:8px 0; border-bottom:1px solid #eef1ef; }
        .row:last-child { border-bottom:0; }
        .btn { display:block; text-align:center; margin-top:12px; background:#0f6e4f; color:#fff; border:0; border-radius:10px; padding:14px; font:inherit; font-weight:600; text-decoration:none; width:100%; box-sizing:border-box; }
        .ok { background:#e7f4ee; color:#0a5139; border-radius:10px; padding:12px; font-weight:600; margin-bottom:14px; }
        .note { background:#fff4e5; border-radius:10px; padding:12px; }
        .error { color:#b42318; font-size:.9rem; }
        .pill { font-size:.8rem; border-radius:999px; padding:2px 10px; white-space:nowrap; align-self:flex-start; background:#fff4e5; }
        .pill.done { background:#e7f4ee; }
        a { color:#0f6e4f; }
        label { display:block; font-weight:600; margin:12px 0 6px; }
        select, textarea, input[type=file] { width:100%; box-sizing:border-box; font:inherit; padding:10px; border:1px solid #d5dbd7; border-radius:10px; background:#fff; }
        textarea { min-height:80px; }
        details summary { cursor:pointer; color:#0f6e4f; font-weight:600; margin-top:8px; }
        .hp { position:absolute; left:-9999px; }
        .lang { display:block; text-align:center; color:#0f6e4f; margin-top:8px; }
    </style>
</head>
<body>
<div class="wrap">
    @if (session('sent'))
        <div class="ok" role="status">{{ __('repairs.page.sent', [], $l) }}</div>
    @endif

    <div class="card">
        <div class="brand">{{ config('app.name') }}</div>
        <h1>{{ $p['tenant'] ? __('portal.hello', ['name' => $p['tenant']], $l) : __('portal.title', [], $l) }}</h1>
        <div class="muted">{{ $p['unit'] }} · {{ $p['property'] }}</div>
        @unless ($p['active'])
            <div class="note" style="margin-top:10px">{{ __('portal.ended', ['date' => $p['lease_to']], $l) }}</div>
        @endunless
    </div>

    @if ($p['active'])
        <div class="card">
            @if ($p['balance_raw'] > 0)
                <div class="muted">{{ __('portal.overdue', [], $l) }}</div>
                <div class="big due">{{ $p['balance'] }}</div>
            @else
                <div class="muted">{{ __('portal.balance', [], $l) }}</div>
                <div class="big">{{ __('portal.all_paid', [], $l) }}</div>
            @endif
            @if ($p['next_due'])
                <div class="row" style="margin-top:8px">
                    <span>{{ $p['next_due']['label'] }}<br><span class="muted">{{ __('portal.due_on', ['date' => $p['next_due']['date']], $l) }}</span></span>
                    <strong>{{ $p['next_due']['amount'] }}</strong>
                </div>
            @endif
            @if ($p['pay_url'])
                <a class="btn" href="{{ $p['pay_url'] }}">{{ __('portal.pay_online', [], $l) }}</a>
            @endif
        </div>
    @endif

    @if ($p['statement_url'])
        <div class="card">
            <a class="btn" href="{{ $p['statement_url'] }}">{{ __('portal.statement', [], $l) }}</a>
        </div>
    @endif

    <div class="card">
        <h2>{{ __('portal.payments', [], $l) }}</h2>
        @forelse ($p['payments'] as $pay)
            <div class="row">
                <span>{{ $pay['date'] }}<br><span class="muted">{{ $pay['method'] }}</span></span>
                <span style="text-align:right"><strong>{{ $pay['amount'] }}</strong><br><a href="{{ $pay['receipt_url'] }}">{{ __('portal.receipt', [], $l) }}</a></span>
            </div>
        @empty
            <div class="muted">{{ __('portal.no_payments', [], $l) }}</div>
        @endforelse
    </div>

    <div class="card">
        <h2>{{ __('portal.repairs', [], $l) }}</h2>
        @foreach ($p['repairs'] as $r)
            <div class="row">
                <span>{{ $r['title'] }}<br><span class="muted">{{ $r['date'] }}</span></span>
                <span class="pill {{ $r['done'] ? 'done' : '' }}">{{ $r['status'] }}</span>
            </div>
        @endforeach
        @if ($p['repair_url'])
            <details @if ($errors->any()) open @endif>
                <summary>{{ __('repairs.page.title', [], $l) }}</summary>
                <form method="POST" action="{{ $p['repair_url'] }}" enctype="multipart/form-data">
                    @csrf
                    <input type="hidden" name="back" value="portal">
                    <div class="hp" aria-hidden="true"><input name="website" tabindex="-1" autocomplete="off"></div>
                    <label for="category">{{ __('repairs.page.what', [], $l) }}</label>
                    <select id="category" name="category" required>
                        @foreach (\App\Models\MaintenanceRequest::CATEGORIES as $category)
                            <option value="{{ $category }}" @selected(old('category') === $category)>{{ __('repairs.categories.'.$category, [], $l) }}</option>
                        @endforeach
                    </select>
                    <label for="description">{{ __('repairs.page.describe', [], $l) }}</label>
                    <textarea id="description" name="description" required maxlength="1000">{{ old('description') }}</textarea>
                    @error('description') <div class="error">{{ $message }}</div> @enderror
                    <label for="photo">{{ __('repairs.page.photo', [], $l) }}</label>
                    <input id="photo" name="photo" type="file" accept="image/*" capture="environment">
                    @error('photo') <div class="error">{{ $message }}</div> @enderror
                    <button class="btn" type="submit">{{ __('repairs.page.submit', [], $l) }}</button>
                </form>
            </details>
        @elseif (empty($p['repairs']))
            <div class="muted">{{ __('portal.no_repairs', [], $l) }}</div>
        @endif
    </div>

    @if ($p['announcements'])
        <div class="card">
            <h2>{{ __('portal.announcements', [], $l) }}</h2>
            @foreach ($p['announcements'] as $a)
                <div class="row"><span>{{ $a['body'] }}<br><span class="muted">{{ $a['date'] }}</span></span></div>
            @endforeach
        </div>
    @endif

    <div class="card">
        <h2>{{ __('portal.lease', [], $l) }}</h2>
        <div class="row"><span>{{ __('portal.lease_dates', [], $l) }}</span><span>{{ $p['lease_from'] }} – {{ $p['lease_to'] }}</span></div>
        <div class="row"><span>{{ __('portal.monthly_rent', [], $l) }}</span><span>{{ $p['monthly_rent'] }}</span></div>
        @if ($p['days_left'] !== null)
            <div class="row"><span>{{ __('portal.days_left', [], $l) }}</span><span>{{ $p['days_left'] }}</span></div>
        @endif
        @if ($p['landlord_phone'])
            <div class="row"><span>{{ __('portal.landlord', [], $l) }}</span><span>@if ($p['landlord']){{ $p['landlord'] }} · @endif<a href="tel:{{ $p['landlord_phone'] }}">{{ $p['landlord_phone'] }}</a></span></div>
        @endif
    </div>

    <div class="muted" style="text-align:center">{{ __('portal.private', [], $l) }}</div>
    <a class="lang" href="{{ route('privacy') }}">{{ __('privacy.title', [], $l) }}</a>
    <a class="lang" href="?lang={{ $l === 'am' ? 'en' : 'am' }}">{{ $l === 'am' ? 'English' : 'አማርኛ' }}</a>
</div>
</body>
</html>
