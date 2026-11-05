<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('repairs.page.title') }} — {{ config('app.name') }}</title>
    {{-- Opened from an SMS on a basic phone: no scripts, no web fonts, small. --}}
    <style>
        body { margin:0; background:#f7f8f6; color:#1d2421; font-family:"Noto Sans Ethiopic","Nyala","Abyssinica SIL",system-ui,sans-serif; line-height:1.5; }
        .wrap { max-width:520px; margin:0 auto; padding:16px; }
        .card { background:#fff; border:1px solid #e1e5e2; border-radius:14px; padding:18px; margin-bottom:14px; }
        h1 { font-size:1.3rem; margin:0 0 4px; }
        .muted { color:#5b6661; font-size:.9rem; }
        label { display:block; font-weight:600; margin:14px 0 6px; }
        select, textarea, input[type=file] { width:100%; box-sizing:border-box; font:inherit; padding:10px; border:1px solid #d5dbd7; border-radius:10px; background:#fff; }
        textarea { min-height:100px; }
        .btn { margin-top:16px; width:100%; background:#0f6e4f; color:#fff; border:0; border-radius:10px; padding:14px; font:inherit; font-weight:600; }
        .ok { background:#e7f4ee; color:#0a5139; border-radius:10px; padding:12px; font-weight:600; }
        .error { color:#b42318; font-size:.9rem; }
        .item { display:flex; justify-content:space-between; gap:8px; padding:8px 0; border-bottom:1px solid #eef1ef; }
        .item:last-child { border-bottom:0; }
        .pill { font-size:.8rem; border-radius:999px; padding:2px 10px; white-space:nowrap; align-self:flex-start; }
        .s-open { background:#fff4e5; } .s-in_progress { background:#e8f0fe; } .s-done { background:#e7f4ee; }
        .hp { position:absolute; left:-9999px; }
        .lang { display:block; text-align:center; color:#0f6e4f; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>{{ __('repairs.page.title') }}</h1>
        <div class="muted">{{ $lease->unit->label }}@if ($lease->unit->property) · {{ $lease->unit->property->name }}@endif</div>
        <div class="muted">{{ __('repairs.page.hello', ['name' => $lease->tenant->full_name]) }}</div>
    </div>

    <div class="card">
        @if (session('sent'))
            <div class="ok" role="status">{{ __('repairs.page.sent') }}</div>
        @endif

        <form method="POST" action="{{ route('repairs.public.store', $token) }}" enctype="multipart/form-data">
            @csrf
            <div class="hp" aria-hidden="true"><input name="website" tabindex="-1" autocomplete="off"></div>

            <label for="category">{{ __('repairs.page.what') }}</label>
            <select id="category" name="category" required>
                @foreach ($categories as $category)
                    <option value="{{ $category }}" @selected(old('category') === $category)>{{ __('repairs.categories.'.$category) }}</option>
                @endforeach
            </select>
            @error('category') <div class="error">{{ $message }}</div> @enderror

            <label for="description">{{ __('repairs.page.describe') }}</label>
            <textarea id="description" name="description" required maxlength="1000">{{ old('description') }}</textarea>
            @error('description') <div class="error">{{ $message }}</div> @enderror

            <label for="photo">{{ __('repairs.page.photo') }}</label>
            <input id="photo" name="photo" type="file" accept="image/*" capture="environment">
            @error('photo') <div class="error">{{ $message }}</div> @enderror

            <button class="btn" type="submit">{{ __('repairs.page.submit') }}</button>
        </form>
    </div>

    @if ($recent->isNotEmpty())
        <div class="card">
            <strong>{{ __('repairs.page.recent') }}</strong>
            @foreach ($recent as $item)
                <div class="item">
                    <div>
                        <div>{{ $item->title }}</div>
                        <div class="muted">{{ \App\Support\DateDisplay::date($item->opened_at, app()->getLocale(), $lease->landlord->calendar_pref ?? 'gregorian') }}@if ($item->resolution_note) · {{ $item->resolution_note }}@endif</div>
                    </div>
                    <span class="pill s-{{ $item->status }}">{{ __('repairs.statuses.'.$item->status) }}</span>
                </div>
            @endforeach
        </div>
    @endif

    <p class="muted" style="text-align:center">{{ __('repairs.page.privacy') }} <a href="{{ route('privacy') }}">{{ __('privacy.title') }}</a></p>
    <a class="lang" href="?lang={{ app()->getLocale() === 'am' ? 'en' : 'am' }}">{{ app()->getLocale() === 'am' ? 'English' : 'አማርኛ' }}</a>
</div>
</body>
</html>
