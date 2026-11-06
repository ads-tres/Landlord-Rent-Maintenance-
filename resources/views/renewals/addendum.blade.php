<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <style>
        @font-face { font-family: "Noto Sans Ethiopic"; font-weight: 400; src: url("{{ resource_path('fonts/NotoSansEthiopic-400.ttf') }}") format("truetype"); }
        @font-face { font-family: "Noto Sans Ethiopic"; font-weight: 700; src: url("{{ resource_path('fonts/NotoSansEthiopic-700.ttf') }}") format("truetype"); }
        @page { margin: 36px; }
        body { font-family: "Noto Sans Ethiopic", sans-serif; color:#1d2421; font-size: 12px; line-height: 1.6; }
        .brand { font-weight:700; color:#0f6e4f; font-size:16px; }
        h1 { font-size: 20px; margin: 6px 0 14px; }
        table { width:100%; border-collapse:collapse; margin: 12px 0; }
        th, td { padding:7px 0; border-bottom:1px solid #e6e9e7; text-align:left; vertical-align:top; }
        th { color:#5b6661; font-weight:400; width:40%; }
        .muted { color:#5b6661; }
        .sign { width:100%; margin-top: 48px; }
        .sign td { border:0; width:50%; padding-top: 36px; }
        .line { border-top:1px solid #1d2421; padding-top:4px; width: 85%; }
    </style>
</head>
<body>
    <div class="brand">{{ config('app.name') }}</div>
    <h1>{{ __('renewals.addendum.title', [], $locale) }}</h1>
    <p>{{ __('renewals.addendum.intro', ['landlord' => $landlord, 'tenant' => $tenant, 'place' => $place, 'start' => $start], $locale) }}</p>

    <table>
        <tr><th>{{ __('renewals.addendum.place', [], $locale) }}</th><td>{{ $place }}</td></tr>
        <tr><th>{{ __('renewals.addendum.previous_end', [], $locale) }}</th><td>{{ $previousEnd }}</td></tr>
        <tr><th>{{ __('renewals.addendum.new_end', [], $locale) }}</th><td><strong>{{ $newEnd }}</strong></td></tr>
        <tr><th>{{ __('renewals.addendum.old_rent', [], $locale) }}</th><td>{{ $oldRent }}</td></tr>
        <tr><th>{{ __('renewals.addendum.new_rent', ['date' => $rentFrom], $locale) }}</th><td><strong>{{ $newRent }}</strong>@if ($increase > 0) <span class="muted">(+{{ $increase }}%)</span>@endif</td></tr>
    </table>

    <p>{{ __('renewals.addendum.rest_unchanged', [], $locale) }}</p>
    @if ($increase > 0)
        <p class="muted">{{ __('renewals.addendum.cap_note', ['cap' => $cap], $locale) }}</p>
    @endif
    <p class="muted">{{ __('renewals.addendum.register', [], $locale) }}</p>

    <table class="sign">
        <tr>
            <td><div class="line">{{ __('renewals.addendum.landlord_sign', ['name' => $landlord], $locale) }}</div></td>
            <td><div class="line">{{ __('renewals.addendum.tenant_sign', ['name' => $tenant], $locale) }}</div></td>
        </tr>
    </table>
    <p class="muted">{{ __('renewals.addendum.date', ['date' => $today], $locale) }}</p>
</body>
</html>
