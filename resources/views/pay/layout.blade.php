<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') — {{ config('app.name') }}</title>
    <style>
        body { margin:0; background:#f7f8f6; color:#1d2421; font-family:"Noto Sans Ethiopic","Nyala","Abyssinica SIL",system-ui,sans-serif; line-height:1.5; }
        .wrap { max-width:520px; margin:0 auto; padding:16px; }
        .card { background:#fff; border:1px solid #e1e5e2; border-radius:14px; padding:18px; margin-bottom:14px; }
        h1 { font-size:1.3rem; margin:0 0 4px; }
        .big { font-size:2rem; font-weight:700; }
        .muted { color:#5b6661; font-size:.9rem; }
        .btn { display:block; width:100%; box-sizing:border-box; text-align:center; margin-top:12px; background:#0f6e4f; color:#fff; border:0; border-radius:10px; padding:14px; font:inherit; font-weight:600; text-decoration:none; }
        .btn.light { background:#e7f4ee; color:#0a5139; }
        .ok { background:#e7f4ee; color:#0a5139; border-radius:10px; padding:12px; font-weight:600; }
        .warn { background:#fff4e5; border-radius:10px; padding:12px; }
        .error { color:#b42318; }
        a { color:#0f6e4f; }
    </style>
</head>
<body>
<div class="wrap">
    @yield('content')
    <p class="muted" style="text-align:center">
        <a href="?lang={{ app()->getLocale() === 'am' ? 'en' : 'am' }}">{{ app()->getLocale() === 'am' ? 'English' : 'አማርኛ' }}</a> ·
        <a href="{{ route('privacy') }}">{{ __('privacy.title') }}</a>
    </p>
</div>
</body>
</html>
