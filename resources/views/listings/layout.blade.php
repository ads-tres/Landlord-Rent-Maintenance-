<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') — {{ config('app.name') }}</title>
    {{-- Opened from Telegram or Facebook on mobile data: no scripts, no web fonts. --}}
    <style>
        body { margin:0; background:#f7f8f6; color:#1d2421; font-family:"Noto Sans Ethiopic","Nyala","Abyssinica SIL",system-ui,sans-serif; line-height:1.5; }
        .wrap { max-width:560px; margin:0 auto; padding:16px; }
        .card { background:#fff; border:1px solid #e1e5e2; border-radius:14px; padding:18px; margin-bottom:14px; }
        h1 { font-size:1.35rem; margin:0 0 4px; }
        .rent { font-size:1.6rem; font-weight:700; color:#0f6e4f; }
        .muted { color:#5b6661; font-size:.9rem; }
        .photos { display:flex; gap:8px; overflow-x:auto; margin-bottom:14px; }
        .photos img { height:180px; border-radius:10px; }
        label { display:block; font-weight:600; margin:12px 0 6px; }
        input, textarea { width:100%; box-sizing:border-box; font:inherit; padding:10px; border:1px solid #d5dbd7; border-radius:10px; background:#fff; }
        input[type=checkbox] { width:auto; }
        .btn { margin-top:16px; width:100%; background:#0f6e4f; color:#fff; border:0; border-radius:10px; padding:14px; font:inherit; font-weight:600; }
        .ok { background:#e7f4ee; color:#0a5139; border-radius:10px; padding:12px; font-weight:600; }
        .error { color:#b42318; font-size:.9rem; }
        .hp { position:absolute; left:-9999px; }
        .link { background:none; border:0; color:#5b6661; text-decoration:underline; font:inherit; font-size:.85rem; cursor:pointer; padding:0; }
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
