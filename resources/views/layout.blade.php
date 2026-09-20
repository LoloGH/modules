<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', __('finance::messages.home.title'))</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: system-ui, sans-serif; margin: 0; color: #1f2933; background: #f5f7fa; }
        header.top { display: flex; flex-wrap: wrap; gap: 1rem; align-items: center; padding: .75rem 1.5rem; background: #0b5563; color: #fff; }
        header.top a { color: #fff; text-decoration: none; }
        header.top .brand { font-weight: 700; font-size: 1.1rem; }
        header.top nav { display: flex; gap: 1rem; flex: 1; }
        header.top nav a { opacity: .9; } header.top nav a:hover { text-decoration: underline; }
        main { max-width: 64rem; margin: 1.5rem auto; padding: 0 1rem; }
        h1 { margin: 0 0 .5rem; } h2 { margin: 0 0 .75rem; font-size: 1.1rem; }
        .card { background: #fff; border-radius: .5rem; padding: 1.25rem; margin-bottom: 1rem; box-shadow: 0 1px 2px rgba(0,0,0,.06); }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr)); gap: 1rem; }
        .grid .kpi { background: #f0f4f8; border-radius: .4rem; padding: .75rem; }
        .kpi small { display: block; color: #616e7c; } .kpi strong { font-size: 1.2rem; }
        .muted { color: #616e7c; }
        label { display: block; margin-bottom: .6rem; font-size: .9rem; color: #323f4b; }
        input, select, textarea { display: block; width: 100%; max-width: 26rem; padding: .45rem .5rem; margin-top: .2rem; border: 1px solid #cbd2d9; border-radius: .3rem; font: inherit; }
        button { padding: .5rem 1rem; border: 0; border-radius: .3rem; background: #0b5563; color: #fff; font: inherit; cursor: pointer; }
        button.danger { background: #b42318; } button.secondary { background: #52606d; }
        table { width: 100%; border-collapse: collapse; font-size: .92rem; }
        th, td { text-align: left; padding: .45rem .5rem; border-bottom: 1px solid #e4e7eb; vertical-align: top; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        tr.cancelled td { color: #9aa5b1; text-decoration: line-through; } tr.cancelled td form, tr.cancelled td .why { text-decoration: none; }
        .badge { display: inline-block; padding: .1rem .5rem; border-radius: 1rem; font-size: .8rem; font-weight: 600; vertical-align: middle; }
        .badge.open { background: #d1fadf; color: #05603a; } .badge.closed { background: #fef0c7; color: #93370d; } .badge.validated { background: #d1e9ff; color: #175cd3; }
        .flash { padding: .75rem 1rem; border-radius: .4rem; margin-bottom: 1rem; }
        .flash.ok { background: #d1fadf; color: #05603a; } .flash.err { background: #fee4e2; color: #912018; }
        .flash ul { margin: 0; padding-left: 1.1rem; }
        .neg { color: #b42318; font-weight: 600; } .pos { color: #93370d; font-weight: 600; } .zero { color: #05603a; font-weight: 600; }
        form.inline { display: flex; gap: .4rem; align-items: center; } form.inline input { margin: 0; max-width: 12rem; }
        .two { display: grid; grid-template-columns: repeat(auto-fit, minmax(20rem, 1fr)); gap: 1rem; }
    </style>
</head>
<body>
<header class="top">
    <a class="brand" href="{{ route('finance.home') }}">{{ __('finance::messages.home.title') }}</a>
    <nav>
        @can('finance.sessions.view')<a href="{{ route('finance.cash.index') }}">Ma caisse</a>@endcan
        @can('finance.sessions.validate')<a href="{{ route('finance.review.index') }}">Sessions à valider</a>@endcan
        @can('finance.registers.manage')<a href="{{ route('finance.registers.index') }}">Caisses</a>@endcan
        @can('finance.catalog.view')<a href="{{ route('finance.catalog.acts.index') }}">Actes</a>@endcan
        @can('finance.catalog.view')<a href="{{ route('finance.catalog.centers.index') }}">Centres analytiques</a>@endcan
    </nav>
    <span>{{ auth()->user()?->name }}</span>
</header>
<main>
    @if (session('finance_status'))
        <div class="flash ok">{{ session('finance_status') }}</div>
    @endif
    @if (session('finance_error'))
        <div class="flash err">{{ session('finance_error') }}</div>
    @endif
    @if ($errors->any())
        <div class="flash err"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    @yield('content')
</main>
</body>
</html>
