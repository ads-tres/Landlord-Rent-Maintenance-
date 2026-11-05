<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }} — {{ __('landing.title') }}</title>
    <meta name="description" content="{{ __('landing.tagline') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Ethiopic:wght@400;600;700&display=swap" rel="stylesheet">
    {{-- Kept small on purpose: no JS framework, one font, inline CSS (target < 100 KB on slow mobile data). --}}
    <style>
        :root { --green:#0f6e4f; --green-dark:#0a5139; --ink:#1d2421; --muted:#5b6661; --bg:#f7f8f6; --card:#fff; --line:#e1e5e2; }
        * { box-sizing: border-box; }
        body { margin:0; font-family:"Noto Sans Ethiopic", system-ui, sans-serif; color:var(--ink); background:var(--bg); line-height:1.6; }
        .wrap { max-width: 880px; margin: 0 auto; padding: 0 16px; }
        header { display:flex; justify-content:space-between; align-items:center; padding:16px 0; }
        .brand { font-weight:700; font-size:1.25rem; color:var(--green); text-decoration:none; }
        .lang { color:var(--green); font-weight:600; text-decoration:none; border:1px solid var(--green); border-radius:999px; padding:4px 14px; }
        .hero { padding: 40px 0 32px; }
        h1 { font-size: clamp(1.7rem, 5vw, 2.6rem); line-height:1.25; margin:0 0 12px; }
        .tagline { font-size:1.15rem; color:var(--muted); margin:0 0 24px; }
        .btn { display:inline-block; background:var(--green); color:#fff; border:0; border-radius:10px; padding:14px 22px; font:inherit; font-weight:600; text-decoration:none; cursor:pointer; }
        .btn:hover { background:var(--green-dark); }
        section { padding: 28px 0; }
        h2 { font-size:1.4rem; margin:0 0 16px; }
        ul.problems { padding-left:20px; margin:0; }
        ul.problems li { margin-bottom:8px; }
        .grid { display:grid; gap:16px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
        .card { background:var(--card); border:1px solid var(--line); border-radius:14px; padding:20px; }
        .card h3 { margin:0 0 6px; font-size:1.05rem; color:var(--green-dark); }
        .card p { margin:0; color:var(--muted); }
        form .field { margin-bottom:16px; }
        label { display:block; font-weight:600; margin-bottom:6px; }
        input, select, textarea { width:100%; font:inherit; padding:12px; border:1px solid var(--line); border-radius:10px; background:#fff; }
        textarea { min-height:90px; resize:vertical; }
        .error { color:#b42318; font-size:.9rem; margin-top:4px; }
        .notice { background:#e7f4ee; border:1px solid #b9e0cd; color:var(--green-dark); padding:14px; border-radius:10px; margin-bottom:16px; font-weight:600; }
        .small { font-size:.85rem; color:var(--muted); }
        .hp { position:absolute; left:-9999px; }
        footer { padding:24px 0 40px; color:var(--muted); font-size:.85rem; border-top:1px solid var(--line); margin-top:16px; }
    </style>
</head>
<body>
<div class="wrap">
    <header>
        <a class="brand" href="{{ route('landing') }}">{{ config('app.name') }}</a>
        <a class="lang" href="?lang={{ app()->getLocale() === 'am' ? 'en' : 'am' }}">{{ __('landing.language') }}</a>
    </header>

    <div class="hero">
        <h1>{{ __('landing.title') }}</h1>
        <p class="tagline">{{ __('landing.tagline') }}</p>
        <a class="btn" href="#join">{{ __('landing.cta') }}</a>
    </div>

    <section>
        <h2>{{ __('landing.problem_title') }}</h2>
        <ul class="problems">
            @foreach (__('landing.problems') as $problem)
                <li>{{ $problem }}</li>
            @endforeach
        </ul>
    </section>

    <section>
        <h2>{{ __('landing.features_title') }}</h2>
        <div class="grid">
            @foreach (__('landing.features') as $feature)
                <div class="card">
                    <h3>{{ $feature['title'] }}</h3>
                    <p>{{ $feature['body'] }}</p>
                </div>
            @endforeach
        </div>
    </section>

    <section id="join">
        <div class="card">
            <h2>{{ __('landing.form_title') }}</h2>
            <p class="small">{{ __('landing.form_intro') }}</p>

            @if (session('joined'))
                <div class="notice" role="status">{{ __('landing.joined') }}</div>
            @endif

            <form method="POST" action="{{ route('waitlist.store') }}" novalidate>
                @csrf
                <input type="hidden" name="source" value="{{ old('source', $source) }}">
                <div class="hp" aria-hidden="true">
                    <label for="website">Website</label>
                    <input id="website" name="website" type="text" tabindex="-1" autocomplete="off">
                </div>

                <div class="field">
                    <label for="phone">{{ __('landing.phone') }}</label>
                    <input id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" placeholder="09XX XXX XXX" value="{{ old('phone') }}" required>
                    @error('phone') <div class="error">{{ $message }}</div> @enderror
                </div>

                <div class="field">
                    <label for="name">{{ __('landing.name') }}</label>
                    <input id="name" name="name" type="text" autocomplete="name" value="{{ old('name') }}">
                    @error('name') <div class="error">{{ $message }}</div> @enderror
                </div>

                <div class="field">
                    <label for="unit_count">{{ __('landing.unit_count') }}</label>
                    <input id="unit_count" name="unit_count" type="number" inputmode="numeric" min="1" max="500" value="{{ old('unit_count') }}" required>
                    @error('unit_count') <div class="error">{{ $message }}</div> @enderror
                </div>

                <div class="field">
                    <label for="current_tracking">{{ __('landing.current_tracking') }}</label>
                    <select id="current_tracking" name="current_tracking">
                        <option value=""></option>
                        @foreach ($trackingOptions as $option)
                            <option value="{{ $option }}" @selected(old('current_tracking') === $option)>{{ __('landing.tracking.'.$option) }}</option>
                        @endforeach
                    </select>
                    @error('current_tracking') <div class="error">{{ $message }}</div> @enderror
                </div>

                <div class="field">
                    <label for="biggest_problem">{{ __('landing.biggest_problem') }}</label>
                    <textarea id="biggest_problem" name="biggest_problem">{{ old('biggest_problem') }}</textarea>
                    @error('biggest_problem') <div class="error">{{ $message }}</div> @enderror
                </div>

                <div class="field">
                    <label for="monthly_price_birr">{{ __('landing.monthly_price') }}</label>
                    <input id="monthly_price_birr" name="monthly_price_birr" type="number" inputmode="numeric" min="0" value="{{ old('monthly_price_birr') }}">
                    @error('monthly_price_birr') <div class="error">{{ $message }}</div> @enderror
                </div>

                <button class="btn" type="submit">{{ __('landing.submit') }}</button>
                <p class="small">{{ __('landing.privacy') }}</p>
            </form>
        </div>
    </section>

    <footer>
        <div>&copy; {{ date('Y') }} {{ config('app.name') }}</div>
        <div>{{ __('landing.disclaimer') }}</div>
    </footer>
</div>
</body>
</html>
