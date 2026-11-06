<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <style>
        @font-face { font-family: "Noto Sans Ethiopic"; font-weight: 400; src: url("{{ resource_path('fonts/NotoSansEthiopic-400.ttf') }}") format("truetype"); }
        @font-face { font-family: "Noto Sans Ethiopic"; font-weight: 700; src: url("{{ resource_path('fonts/NotoSansEthiopic-700.ttf') }}") format("truetype"); }
        @page { margin: 36px; }
        body { font-family: "Noto Sans Ethiopic", sans-serif; color:#1d2421; font-size: 12px; line-height: 1.45; }
        h1 { font-size: 20px; margin: 0; }
        .brand { color:#0f6e4f; font-weight:700; }
        .muted { color:#5b6661; }
        .total { font-size: 26px; font-weight:700; margin: 18px 0 2px; }
        h2 { font-size: 14px; margin: 22px 0 6px; }
        table { width:100%; border-collapse:collapse; }
        th, td { padding:6px 0; border-bottom:1px solid #e6e9e7; text-align:left; }
        th { color:#5b6661; font-weight:400; }
        .right { text-align:right; }
        .indent { padding-left: 16px; color:#5b6661; }
        .strong { font-weight:700; }
        .note { margin-top: 26px; font-size: 10px; color:#5b6661; }
    </style>
</head>
<body>
    <div class="brand">{{ config('app.name') }}</div>
    <h1>{{ __('reports.title') }} · {{ $r['label'] }}</h1>
    <div class="muted">{{ $r['from_display'] }} – {{ $r['to_display'] }} · {{ $landlord }}</div>

    <div class="total">{{ $birr($r['total']) }}</div>
    <div class="muted">{{ trans_choice('reports.payments_count', $r['payments_count'], ['count' => $r['payments_count']]) }}</div>

    <h2>{{ __('reports.by_month') }}</h2>
    <table>
        @forelse ($r['by_month'] as $m)
            <tr><td>{{ $m['label'] }}</td><td class="right">{{ $birr($m['total']) }}</td></tr>
        @empty
            <tr><td class="muted">{{ __('reports.none') }}</td><td></td></tr>
        @endforelse
        <tr><td class="strong">{{ __('reports.total') }}</td><td class="right strong">{{ $birr($r['total']) }}</td></tr>
    </table>

    <h2>{{ __('reports.expenses') }}</h2>
    <table>
        @forelse ($r['expenses_by_category'] as $c)
            <tr><td>{{ $c['label'] }}</td><td class="right">{{ $birr($c['total']) }}</td></tr>
        @empty
            <tr><td class="muted">{{ __('reports.no_expenses') }}</td><td></td></tr>
        @endforelse
        <tr><td class="strong">{{ __('reports.expenses_total') }}</td><td class="right strong">{{ $birr($r['expenses_total']) }}</td></tr>
    </table>

    <h2>{{ __('reports.net') }}</h2>
    <table>
        <tr><td>{{ __('reports.total') }}</td><td class="right">{{ $birr($r['total']) }}</td></tr>
        @if ($r['refunded'] > 0)
            <tr><td>{{ __('reports.refunded') }}</td><td class="right">− {{ $birr($r['refunded']) }}</td></tr>
        @endif
        <tr><td>{{ __('reports.expenses_total') }}</td><td class="right">− {{ $birr($r['expenses_total']) }}</td></tr>
        <tr><td class="strong">{{ __('reports.net') }}</td><td class="right strong">{{ $r['net'] < 0 ? '− ' : '' }}{{ $birr(abs($r['net'])) }}</td></tr>
    </table>

    @if ($r['by_property'])
        <h2>{{ __('reports.by_property') }}</h2>
        <table>
            @foreach ($r['by_property'] as $p)
                <tr><td class="strong">{{ $p['property'] }}</td><td class="right strong">{{ $birr($p['total']) }}</td></tr>
                @foreach ($p['units'] as $u)
                    <tr><td class="indent">{{ $u['unit'] }}</td><td class="right muted">{{ $birr($u['total']) }}</td></tr>
                @endforeach
            @endforeach
        </table>
    @endif

    @if ($r['by_method'])
        <h2>{{ __('reports.by_method') }}</h2>
        <table>
            @foreach ($r['by_method'] as $m)
                <tr><td>{{ __('payments.methods.'.$m['method']) }}</td><td class="right">{{ $birr($m['total']) }}</td></tr>
            @endforeach
        </table>
    @endif

    <p class="note">{{ __('reports.disclaimer') }}</p>
    <p class="note">{{ __('reports.expenses_note') }}</p>
    <p class="note">{{ __('reports.generated', ['date' => \App\Support\DateDisplay::date(now(), $locale, $r['calendar'])]) }}</p>
</body>
</html>
