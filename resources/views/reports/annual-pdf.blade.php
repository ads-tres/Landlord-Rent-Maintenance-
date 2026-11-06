<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <style>
        @font-face { font-family: "Noto Sans Ethiopic"; font-weight: 400; src: url("{{ resource_path('fonts/NotoSansEthiopic-400.ttf') }}") format("truetype"); }
        @font-face { font-family: "Noto Sans Ethiopic"; font-weight: 700; src: url("{{ resource_path('fonts/NotoSansEthiopic-700.ttf') }}") format("truetype"); }
        @page { margin: 32px; }
        body { font-family: "Noto Sans Ethiopic", sans-serif; color:#1d2421; font-size: 11px; line-height: 1.45; }
        .brand { font-weight:700; color:#0f6e4f; font-size:14px; }
        h1 { font-size: 18px; margin: 4px 0; }
        h2 { font-size: 13px; margin: 16px 0 4px; }
        .muted { color:#5b6661; }
        table { width:100%; border-collapse:collapse; margin-top: 6px; }
        th, td { padding:5px 4px; border-bottom:1px solid #e6e9e7; text-align:left; }
        th { color:#5b6661; }
        .right { text-align:right; }
        .strong { font-weight:700; }
        .note { margin-top: 16px; color:#5b6661; font-size: 9.5px; }
    </style>
</head>
<body>
    <div class="brand">{{ config('app.name') }}</div>
    <h1>{{ __('reports.annual_title', [], $locale) }} · {{ $r['label'] }}</h1>
    <div class="muted">{{ $r['from_display'] }} – {{ $r['to_display'] }} · {{ $landlord }}</div>

    <table>
        <tr><td>{{ __('reports.total', [], $locale) }}</td><td class="right">{{ $birr($r['income']) }}</td></tr>
        @if ($r['refunded'] > 0)
            <tr><td>{{ __('reports.refunded', [], $locale) }}</td><td class="right">− {{ $birr($r['refunded']) }}</td></tr>
        @endif
        <tr><td>{{ __('reports.expenses_total', [], $locale) }}</td><td class="right">− {{ $birr($r['expenses']) }}</td></tr>
        <tr><td class="strong">{{ __('reports.net', [], $locale) }}</td><td class="right strong">{{ $r['net'] < 0 ? '− ' : '' }}{{ $birr(abs($r['net'])) }}</td></tr>
        <tr><td>{{ __('reports.average_occupancy', [], $locale) }}</td><td class="right">{{ $r['average_occupancy'] }}%</td></tr>
    </table>

    <h2>{{ __('reports.by_month', [], $locale) }}</h2>
    <table>
        <tr>
            <th></th>
            <th class="right">{{ __('reports.col_income', [], $locale) }}</th>
            <th class="right">{{ __('reports.col_refunds', [], $locale) }}</th>
            <th class="right">{{ __('reports.col_expenses', [], $locale) }}</th>
            <th class="right">{{ __('reports.col_net', [], $locale) }}</th>
            <th class="right">{{ __('reports.col_occupancy', [], $locale) }}</th>
        </tr>
        @foreach ($r['months'] as $m)
            <tr>
                <td>{{ $m['label'] }}</td>
                <td class="right">{{ $birr($m['income']) }}</td>
                <td class="right">{{ $m['refunded'] ? $birr($m['refunded']) : '' }}</td>
                <td class="right">{{ $birr($m['expenses']) }}</td>
                <td class="right">{{ $m['net'] < 0 ? '− ' : '' }}{{ $birr(abs($m['net'])) }}</td>
                <td class="right">{{ $m['occupancy'] }}%</td>
            </tr>
        @endforeach
    </table>

    @if ($r['by_property'])
        <h2>{{ __('reports.by_property', [], $locale) }}</h2>
        <table>
            @foreach ($r['by_property'] as $p)
                <tr><td>{{ $p['property'] }}</td><td class="right">{{ $birr($p['income']) }}</td><td class="right">− {{ $birr($p['refunded'] + $p['expenses']) }}</td><td class="right strong">{{ $p['net'] < 0 ? '− ' : '' }}{{ $birr(abs($p['net'])) }}</td></tr>
            @endforeach
        </table>
    @endif

    <p class="note">{{ __('reports.disclaimer', [], $locale) }} {{ __('reports.occupancy_note', [], $locale) }}</p>
</body>
</html>
