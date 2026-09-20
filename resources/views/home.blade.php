<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('finance::messages.home.title') }}</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; padding: 2rem; color: #1f2933; background: #f5f7fa; }
        main { max-width: 42rem; margin: 0 auto; background: #fff; padding: 2rem; border-radius: .5rem; }
        h1 { margin-top: 0; }
        .muted { color: #616e7c; }
    </style>
</head>
<body>
    <main>
        <h1>{{ __('finance::messages.home.title') }}</h1>
        <p class="muted">{{ $facility }} — {{ __('finance::messages.home.subtitle') }}</p>
        <p>{{ __('finance::messages.home.placeholder') }}</p>
    </main>
</body>
</html>
