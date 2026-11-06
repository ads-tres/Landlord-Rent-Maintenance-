<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <style>
        @font-face { font-family: "Noto Sans Ethiopic"; font-weight: 400; src: url("{{ resource_path('fonts/NotoSansEthiopic-400.ttf') }}") format("truetype"); }
        @font-face { font-family: "Noto Sans Ethiopic"; font-weight: 700; src: url("{{ resource_path('fonts/NotoSansEthiopic-700.ttf') }}") format("truetype"); }
        @page { margin: 40px; }
        body { font-family: "Noto Sans Ethiopic", sans-serif; color:#1d2421; font-size: 12px; line-height: 1.6; }
        h1 { font-size: 18px; margin: 18px 0 10px; }
        table { width:100%; border-collapse:collapse; margin: 10px 0; }
        th, td { padding:6px 0; border-bottom:1px solid #e6e9e7; text-align:left; }
        .right { text-align:right; }
        .strong { font-weight:700; }
        .muted { color:#5b6661; }
        .sign { margin-top: 48px; border-top:1px solid #1d2421; width: 45%; padding-top: 4px; }
    </style>
</head>
<body>
    <div class="right muted">{{ __('arrears.letter.date', ['date' => $today], $locale) }}</div>
    <p>{{ __('arrears.letter.to', ['tenant' => $tenant, 'place' => $place], $locale) }}</p>
    <h1>{{ __('arrears.letter.title', [], $locale) }}</h1>
    <p>{{ __('arrears.letter.intro', ['start' => $start, 'place' => $place], $locale) }}</p>

    <table>
        <tr><th>{{ __('arrears.letter.item', [], $locale) }}</th><th>{{ __('arrears.letter.due', [], $locale) }}</th><th class="right">{{ __('arrears.letter.amount', [], $locale) }}</th></tr>
        @foreach ($lines as $line)
            <tr><td>{{ $line['label'] }}</td><td>{{ $line['due'] }}</td><td class="right">{{ $line['amount'] }}</td></tr>
        @endforeach
        <tr><td class="strong">{{ __('arrears.letter.total', [], $locale) }}</td><td></td><td class="right strong">{{ $total }}</td></tr>
    </table>

    <p>{{ __('arrears.letter.demand', ['total' => $total, 'deadline' => $deadline], $locale) }}</p>
    @if ($payUrl)
        <p>{{ __('arrears.letter.pay_online', ['url' => $payUrl], $locale) }}</p>
    @endif
    <p>{{ __('arrears.letter.plan', ['phone' => $landlordPhone], $locale) }}</p>
    <p class="muted">{{ __('arrears.letter.legal', [], $locale) }}</p>

    <div class="sign">{{ __('arrears.letter.signed', ['name' => $landlord], $locale) }}</div>
</body>
</html>
