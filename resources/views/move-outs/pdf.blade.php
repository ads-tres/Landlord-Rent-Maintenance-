<!DOCTYPE html>
<html lang="{{ $s['locale'] }}">
<head>
    <meta charset="utf-8">
    <style>
        {{-- One font covering Latin and Ge'ez, so Amharic receipts render without missing glyphs. --}}
        @font-face { font-family: "Noto Sans Ethiopic"; font-weight: 400; src: url("{{ resource_path('fonts/NotoSansEthiopic-400.ttf') }}") format("truetype"); }
        @font-face { font-family: "Noto Sans Ethiopic"; font-weight: 700; src: url("{{ resource_path('fonts/NotoSansEthiopic-700.ttf') }}") format("truetype"); }
        @page { margin: 28px; }
        body { font-family: "Noto Sans Ethiopic", sans-serif; color:#1d2421; font-size: 12px; line-height: 1.45; }
        .head { width:100%; }
        .head > div { display:inline-block; vertical-align:top; width:49%; }
        .brand { font-weight:700; color:#0f6e4f; font-size:18px; }
        .muted { color:#5b6661; }
        .strong { font-weight:700; }
        .right { text-align:right; }
        .center { text-align:center; }
        .amount { font-size:28px; font-weight:700; text-align:center; margin:22px 0 4px; }
        table { width:100%; border-collapse:collapse; margin-top:16px; }
        th, td { padding:7px 0; border-bottom:1px solid #e6e9e7; text-align:left; vertical-align:top; }
        .details th { color:#5b6661; font-weight:400; width:35%; }
        .lines th { color:#5b6661; }
        .total td { font-weight:700; border-bottom:0; }
        .note { background:#fff4e5; padding:8px; margin-top:16px; }
        .footer { margin-top:24px; text-align:center; }
    </style>
</head>
<body>
    @include('move-outs._body', ['s' => $s])
</body>
</html>
