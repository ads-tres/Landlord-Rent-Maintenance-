<!DOCTYPE html>
{{-- $docs: one agreement per language (Amharic first when both); clauses line up by position. --}}
@php($main = $docs[0])
@php($second = $docs[1] ?? null)
<html lang="{{ $main['locale'] }}">
<head>
    <meta charset="utf-8">
    <style>
        @font-face { font-family: "Noto Sans Ethiopic"; font-weight: 400; src: url("{{ resource_path('fonts/NotoSansEthiopic-400.ttf') }}") format("truetype"); }
        @font-face { font-family: "Noto Sans Ethiopic"; font-weight: 700; src: url("{{ resource_path('fonts/NotoSansEthiopic-700.ttf') }}") format("truetype"); }
        @page { margin: 40px 44px; }
        body { font-family: "Noto Sans Ethiopic", sans-serif; color:#1d2421; font-size: 11.5px; line-height: 1.55; }
        .brand { font-weight:700; color:#0f6e4f; font-size:13px; }
        h1 { font-size: 19px; margin: 4px 0 2px; }
        .subtitle { color:#5b6661; font-size: 13px; margin-bottom: 14px; }
        .clause { margin: 0 0 10px; page-break-inside: avoid; }
        .clause h2 { font-size: 12.5px; margin: 0 0 2px; }
        .clause h2 .en { font-weight: 400; color:#5b6661; }
        .text { white-space: pre-line; }
        .en-text { white-space: pre-line; color:#5b6661; font-size: 10.5px; margin-top: 2px; }
        .signatures { width:100%; border-collapse:collapse; margin-top: 10px; }
        .signatures td { width:50%; vertical-align:top; padding: 12px 10px 4px 0; }
        .line { border-bottom:1px solid #1d2421; height: 22px; }
        .label { color:#5b6661; font-size: 10.5px; }
        .page { page-break-before: always; }
        ol li { margin-bottom: 6px; }
        .disclaimer { margin-top: 18px; color:#5b6661; font-size: 9.5px; }
    </style>
</head>
<body>
    <div class="brand">{{ config('app.name') }}</div>
    <h1>{{ $main['title'] }}</h1>
    @if ($second)
        <div class="subtitle">{{ $second['title'] }}</div>
    @endif

    @foreach ($main['clauses'] as $i => $clause)
        <div class="clause">
            <h2>{{ $i + 1 }}. {{ $clause['title'] }}@if ($second) <span class="en">/ {{ $second['clauses'][$i]['title'] }}</span>@endif</h2>
            <div class="text">{{ $clause['text'] }}</div>
            @if ($second)
                <div class="en-text">{{ $second['clauses'][$i]['text'] }}</div>
            @endif
        </div>
    @endforeach

    <div class="clause">
        <h2>{{ $main['labels']['signatures'] }}@if ($second) <span class="en">/ {{ $second['labels']['signatures'] }}</span>@endif</h2>
        <div>{{ $main['labels']['signed_on'] }}</div>
        <table class="signatures">
            @foreach ([['landlord', 'tenant'], ['witness_1', 'witness_2']] as $row)
                <tr>
                    @foreach ($row as $who)
                        <td>
                            <strong>{{ $main['labels'][$who] }}</strong>@if ($second) <span class="label">/ {{ $second['labels'][$who] }}</span>@endif
                            <div class="line"></div>
                            <div class="label">{{ $main['labels']['name'] }}@if ($second) / {{ $second['labels']['name'] }}@endif</div>
                            <div class="line"></div>
                            <div class="label">{{ $main['labels']['signature'] }}@if ($second) / {{ $second['labels']['signature'] }}@endif</div>
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </table>
    </div>

    <div class="disclaimer">{{ $main['disclaimer'] }}@if ($second) {{ $second['disclaimer'] }}@endif</div>

    @foreach ($docs as $doc)
        <div class="page">
            <h1>{{ $doc['checklist_title'] }}</h1>
            <ol>
                @foreach ($doc['checklist'] as $item)
                    <li>{{ $item }}</li>
                @endforeach
            </ol>
        </div>
    @endforeach
</body>
</html>
