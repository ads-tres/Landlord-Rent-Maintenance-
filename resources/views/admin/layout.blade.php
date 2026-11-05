<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title', __('admin.title')) — {{ config('app.name') }}</title>
    <style>
        :root { --green:#0f6e4f; --ink:#1d2421; --muted:#5b6661; --line:#e1e5e2; --bg:#f7f8f6; --red:#b42318; }
        * { box-sizing: border-box; }
        body { margin:0; background:var(--bg); color:var(--ink); font-family:"Noto Sans Ethiopic",system-ui,sans-serif; font-size:14px; line-height:1.5; }
        header { background:#fff; border-bottom:1px solid var(--line); }
        header .in, main { max-width:1200px; margin:0 auto; padding:12px 16px; }
        header .in { display:flex; gap:16px; align-items:center; flex-wrap:wrap; }
        header a { color:var(--ink); text-decoration:none; font-weight:600; }
        header .brand { color:var(--green); font-weight:700; margin-right:auto; }
        .card { background:#fff; border:1px solid var(--line); border-radius:12px; padding:16px; margin-bottom:16px; overflow-x:auto; }
        .grid { display:grid; gap:12px; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); margin-bottom:16px; }
        .stat .v { font-size:1.6rem; font-weight:700; } .stat .l { color:var(--muted); }
        table { width:100%; border-collapse:collapse; }
        th, td { text-align:left; padding:8px 6px; border-bottom:1px solid var(--line); vertical-align:top; }
        th { color:var(--muted); font-weight:600; white-space:nowrap; }
        input, select, textarea { font:inherit; padding:8px; border:1px solid #cfd6d2; border-radius:8px; background:#fff; }
        textarea { width:100%; min-height:38px; }
        .btn { background:var(--green); color:#fff; border:0; border-radius:8px; padding:8px 14px; font:inherit; font-weight:600; cursor:pointer; text-decoration:none; display:inline-block; }
        .btn.light { background:#e7f4ee; color:var(--green); }
        .linkbtn { background:none; border:0; color:var(--ink); font:inherit; font-weight:600; cursor:pointer; padding:0; }
        .ok { background:#e7f4ee; color:#0a5139; padding:10px 12px; border-radius:8px; margin-bottom:16px; }
        .err { color:var(--red); }
        .muted { color:var(--muted); }
        .pill { background:#eef1ef; border-radius:999px; padding:1px 8px; font-size:12px; }
        .row { display:flex; gap:8px; flex-wrap:wrap; align-items:end; }
        h1 { font-size:1.3rem; margin:0 0 12px; } h2 { font-size:1.05rem; margin:0 0 10px; }
    </style>
</head>
<body>
@auth
    <header><div class="in">
        <span class="brand">{{ config('app.name') }} · {{ __('admin.title') }}</span>
        <a href="{{ route('admin.dashboard') }}">{{ __('admin.nav.dashboard') }}</a>
        <a href="{{ route('admin.waitlist') }}">{{ __('admin.nav.waitlist') }}</a>
        <a href="{{ route('admin.settings') }}">{{ __('admin.nav.settings') }}</a>
        <form method="POST" action="{{ route('admin.logout') }}">@csrf <button class="linkbtn">{{ __('admin.nav.logout') }}</button></form>
    </div></header>
@endauth
<main>
    @if (session('status')) <div class="ok">{{ session('status') }}</div> @endif
    @if ($errors->any()) <div class="card err">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div> @endif
    @yield('content')
</main>
</body>
</html>
