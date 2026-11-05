<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('privacy.title') }} — {{ config('app.name') }}</title>
    <style>
        body { margin:0; background:#f7f8f6; color:#1d2421; font-family:"Noto Sans Ethiopic","Nyala","Abyssinica SIL",system-ui,sans-serif; line-height:1.6; }
        .wrap { max-width:720px; margin:0 auto; padding:16px; }
        .card { background:#fff; border:1px solid #e1e5e2; border-radius:14px; padding:20px; }
        h1 { font-size:1.5rem; margin:0 0 4px; }
        h2 { font-size:1.1rem; margin:20px 0 6px; }
        .muted { color:#5b6661; font-size:.9rem; }
        a { color:#0f6e4f; }
    </style>
</head>
<body>
<div class="wrap">
    <p><a href="{{ route('landing') }}">{{ config('app.name') }}</a> · <a href="?lang={{ app()->getLocale() === 'am' ? 'en' : 'am' }}">{{ app()->getLocale() === 'am' ? 'English' : 'አማርኛ' }}</a></p>
    <div class="card">
        <h1>{{ __('privacy.title') }}</h1>
        <p class="muted">{{ __('privacy.updated') }}</p>
        @foreach (__('privacy.sections') as $section)
            <h2>{{ $section['h'] }}</h2>
            <p>{{ $section['p'] }}</p>
        @endforeach
    </div>
</div>
</body>
</html>
