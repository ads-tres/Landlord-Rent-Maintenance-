<!DOCTYPE html>
@php($l = $s['locale'])
<html lang="{{ $l }}">
<head>
    <meta charset="utf-8">
    <style>
        @font-face { font-family: "Noto Sans Ethiopic"; font-weight: 400; src: url("{{ resource_path('fonts/NotoSansEthiopic-400.ttf') }}") format("truetype"); }
        @font-face { font-family: "Noto Sans Ethiopic"; font-weight: 700; src: url("{{ resource_path('fonts/NotoSansEthiopic-700.ttf') }}") format("truetype"); }
        @page { margin: 32px; }
        body { font-family: "Noto Sans Ethiopic", sans-serif; color:#1d2421; font-size: 11px; line-height: 1.45; }
        .brand { font-weight:700; color:#0f6e4f; font-size:14px; }
        h1 { font-size: 18px; margin: 4px 0; }
        .muted { color:#5b6661; }
        table { width:100%; border-collapse:collapse; margin-top: 12px; }
        th, td { padding:5px 4px; border-bottom:1px solid #e6e9e7; text-align:left; vertical-align:top; }
        th { color:#5b6661; font-weight:700; }
        .right { text-align:right; }
        .strong { font-weight:700; }
        .summary td { border:0; padding: 2px 4px; }
        .note { margin-top: 16px; color:#5b6661; font-size: 9.5px; }
    </style>
</head>
<body>
    <div class="brand">{{ config('app.name') }}</div>
    <h1>{{ __('statement.title', [], $l) }}</h1>
    <div>{{ $s['tenant'] }} · {{ $s['place'] }}</div>
    <div class="muted">{{ __('statement.period', ['from' => $s['from_display'], 'to' => $s['to_display']], $l) }} · {{ __('statement.landlord', ['name' => $s['landlord']], $l) }}</div>

    <table class="summary">
        <tr><td>{{ __('statement.opening', [], $l) }}</td><td class="right">{{ $s['opening_display'] }}</td></tr>
        <tr><td>{{ __('statement.total_charged', [], $l) }}</td><td class="right">{{ \App\Services\Payments\ReceiptData::birr($s['charged'], $l) }}</td></tr>
        <tr><td>{{ __('statement.total_paid', [], $l) }}</td><td class="right">{{ \App\Services\Payments\ReceiptData::birr($s['paid'], $l) }}</td></tr>
        <tr><td class="strong">{{ __('statement.closing', [], $l) }}</td><td class="right strong">{{ $s['closing_display'] }}</td></tr>
    </table>

    <table>
        <tr>
            <th>{{ __('statement.date', [], $l) }}</th>
            <th>{{ __('statement.description', [], $l) }}</th>
            <th class="right">{{ __('statement.charge', [], $l) }}</th>
            <th class="right">{{ __('statement.paid', [], $l) }}</th>
            <th class="right">{{ __('statement.balance', [], $l) }}</th>
        </tr>
        <tr><td></td><td class="muted">{{ __('statement.opening', [], $l) }}</td><td></td><td></td><td class="right">{{ $s['opening_display'] }}</td></tr>
        @forelse ($s['lines'] as $line)
            <tr>
                <td>{{ $line['date_display'] }}</td>
                <td>{{ $line['description'] }}</td>
                <td class="right">{{ $line['charge_display'] }}</td>
                <td class="right">{{ $line['payment_display'] }}</td>
                <td class="right">{{ $line['balance_display'] }}</td>
            </tr>
        @empty
            <tr><td></td><td class="muted">{{ __('statement.none', [], $l) }}</td><td></td><td></td><td></td></tr>
        @endforelse
    </table>

    <p class="note">{{ __('statement.note', ['date' => \App\Support\DateDisplay::date(now(), $l, $s['calendar'] ?? 'gregorian')], $l) }}</p>
</body>
</html>
