<!DOCTYPE html>
<html lang="{{ $pages[0]['locale'] }}">
<head>
    <meta charset="utf-8">
    <style>
        @font-face { font-family: "Noto Sans Ethiopic"; font-weight: 400; src: url("{{ resource_path('fonts/NotoSansEthiopic-400.ttf') }}") format("truetype"); }
        @font-face { font-family: "Noto Sans Ethiopic"; font-weight: 700; src: url("{{ resource_path('fonts/NotoSansEthiopic-700.ttf') }}") format("truetype"); }
        @page { margin: 40px; }
        body { font-family: "Noto Sans Ethiopic", sans-serif; color:#1d2421; font-size: 12px; line-height: 1.6; }
        h1 { font-size: 18px; margin: 18px 0 10px; }
        table { width:100%; border-collapse:collapse; margin: 10px 0; }
        td { padding:6px 0; border-bottom:1px solid #e6e9e7; text-align:left; }
        .right { text-align:right; }
        .strong { font-weight:700; }
        .muted { color:#5b6661; }
        .sign { margin-top: 44px; border-top:1px solid #1d2421; width: 45%; padding-top: 4px; }
        .page-break { page-break-after: always; }
    </style>
</head>
<body>
@foreach ($pages as $p)
    @php($l = $p['locale'])
    <div class="right muted">{{ __('rent_notice.date', ['date' => $p['today']], $l) }}</div>
    <p>{{ __('rent_notice.to', ['tenant' => $p['tenant'], 'place' => $p['place']], $l) }}</p>
    <h1>{{ __('rent_notice.title', [], $l) }}</h1>
    <p>{{ __('rent_notice.intro', ['place' => $p['place'], 'start' => $p['start']], $l) }}</p>

    <table>
        <tr><td>{{ __('rent_notice.current', [], $l) }}</td><td class="right">{{ $p['old'] }}</td></tr>
        <tr><td class="strong">{{ __('rent_notice.new', [], $l) }}</td><td class="right strong">{{ $p['new'] }}</td></tr>
        <tr><td>{{ __('rent_notice.change', [], $l) }}</td><td class="right">{{ __($p['increase'] ? 'rent_notice.up' : 'rent_notice.down', ['percent' => $p['percent']], $l) }}</td></tr>
        <tr><td class="strong">{{ __('rent_notice.from', [], $l) }}</td><td class="right strong">{{ $p['effective'] }}</td></tr>
    </table>

    @if ($p['increase'])
        <p>{{ __('rent_notice.within_cap', ['cap' => $p['cap']], $l) }}</p>
    @endif
    @if ($p['agreed'])
        <p>{{ __('rent_notice.agreed', [], $l) }}</p>
    @elseif ($p['days'])
        <p>{{ __('rent_notice.notice_days', ['days' => $p['days']], $l) }}</p>
    @endif
    <p>{{ __('rent_notice.rest_same', [], $l) }}</p>
    <p>{{ __('rent_notice.questions', ['phone' => $p['landlordPhone']], $l) }}</p>

    <div class="sign">{{ __('rent_notice.signed', ['name' => $p['landlord']], $l) }}</div>
    <div class="muted">{{ $p['landlordPhone'] }}@if ($p['landlordAddress']) · {{ $p['landlordAddress'] }}@endif</div>
    <div class="sign">{{ __('rent_notice.received', [], $l) }}</div>
    <p class="muted">{{ __('rent_notice.legal', [], $l) }}</p>
    @if (! $loop->last)
        <div class="page-break"></div>
    @endif
@endforeach
</body>
</html>
