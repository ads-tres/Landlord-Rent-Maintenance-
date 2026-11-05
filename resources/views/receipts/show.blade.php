<!DOCTYPE html>
<html lang="{{ $r['locale'] }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $r['number'] }} — {{ config('app.name') }}</title>
    {{-- Tenant page opened from an SMS: no scripts, no web fonts, well under 100 KB. --}}
    <style>
        body { margin:0; background:#f7f8f6; color:#1d2421; font-family:"Noto Sans Ethiopic","Nyala","Abyssinica SIL",system-ui,sans-serif; line-height:1.5; }
        .wrap { max-width:520px; margin:0 auto; padding:16px; }
        .receipt { background:#fff; border:1px solid #e1e5e2; border-radius:14px; padding:20px; }
        .head { display:flex; justify-content:space-between; gap:12px; }
        .brand { font-weight:700; color:#0f6e4f; font-size:1.2rem; }
        .muted { color:#5b6661; font-size:.9rem; }
        .strong { font-weight:700; }
        .right { text-align:right; }
        .center { text-align:center; }
        .amount { font-size:2rem; font-weight:700; text-align:center; margin:20px 0 4px; }
        table { width:100%; border-collapse:collapse; margin-top:16px; }
        th, td { padding:8px 0; border-bottom:1px solid #eef1ef; text-align:left; vertical-align:top; }
        .details th { color:#5b6661; font-weight:400; width:40%; }
        .lines th { color:#5b6661; font-weight:600; font-size:.9rem; }
        .total td { font-weight:700; border-bottom:0; }
        .note { background:#fff4e5; border-radius:8px; padding:10px; margin-top:16px; }
        .footer { margin-top:20px; text-align:center; }
        .lang { display:block; text-align:center; margin-top:12px; color:#0f6e4f; }
    </style>
</head>
<body>
<div class="wrap">
    @include('receipts._body', ['r' => $r])
    <a class="lang" href="?lang={{ $r['locale'] === 'am' ? 'en' : 'am' }}">{{ $r['locale'] === 'am' ? 'English' : 'አማርኛ' }}</a>
</div>
</body>
</html>
