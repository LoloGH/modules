<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', __('pharmacie::messages.home.title')) — {{ __('pharmacie::messages.home.title') }}</title>
    @include('pharmacie::partials.styles')
</head>
<body>
{{-- Tiroir de navigation mobile : une case à cocher, aucun JavaScript. --}}
<input type="checkbox" id="pha-nav" class="nav-switch" hidden>

<div class="shell">
    @include('pharmacie::partials.sidebar')

    <label for="pha-nav" class="scrim" aria-hidden="true"></label>

    <div class="main">
        @include('pharmacie::partials.header')

        <main class="content">
            @if (session('pharmacie_status'))
                <div class="flash ok">
                    <x-pharmacie::icon name="check" /><span>{{ session('pharmacie_status') }}</span>
                    @if (is_array(session('pharmacie_print')))
                        <a class="btn sm" style="margin-left:auto" href="{{ session('pharmacie_print')['url'] }}" target="_blank" rel="noopener">{{ session('pharmacie_print')['label'] }}</a>
                    @endif
                </div>
            @endif
            @if (session('pharmacie_error'))
                <div class="flash err"><x-pharmacie::icon name="alert" /><span>{{ session('pharmacie_error') }}</span></div>
            @endif
            @if ($errors->any())
                <div class="flash err">
                    <x-pharmacie::icon name="alert" />
                    <div>
                        @if ($errors->count() === 1)
                            {{ $errors->first() }}
                        @else
                            <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                        @endif
                    </div>
                </div>
            @endif

            @yield('content')
        </main>
    </div>
</div>
</body>
</html>
