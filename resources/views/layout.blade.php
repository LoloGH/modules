<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', __('finance::messages.home.title')) · {{ __('finance::messages.home.title') }}</title>
    {{-- Pose le theme choisi avant le premier rendu : sans cela, une page
         claire clignerait une fraction de seconde avant de passer au noir. --}}
    <script>
        (function () {
            try {
                var choix = localStorage.getItem('finance.theme');

                if (choix === 'dark' || choix === 'light') {
                    document.documentElement.setAttribute('data-theme', choix);
                }
            } catch (e) {
                // Stockage refuse (navigation privee, reglage du poste) :
                // l'interface suit le systeme, et rien ne casse.
            }
        })();
    </script>
    @include('finance::partials.styles')
</head>
<body>
{{-- Tiroir de navigation mobile : une case à cocher, aucun JavaScript. --}}
<input type="checkbox" id="fin-nav" class="nav-switch" hidden>

<div class="shell">
    @include('finance::partials.sidebar')

    <label for="fin-nav" class="scrim" aria-hidden="true"></label>

    <div class="main">
        @include('finance::partials.header')

        <main class="content">
            @if (session('finance_status'))
                <div class="flash ok">
                    <x-finance::icon name="check" /><span>{{ session('finance_status') }}</span>
                    @if (is_array(session('finance_print')))
                        <a class="btn sm" style="margin-left:auto" href="{{ session('finance_print')['url'] }}" target="_blank" rel="noopener">{{ session('finance_print')['label'] }}</a>
                    @endif
                </div>
            @endif
            @if (session('finance_error'))
                <div class="flash err"><x-finance::icon name="alert" /><span>{{ session('finance_error') }}</span></div>
            @endif
            @if ($errors->any())
                <div class="flash err">
                    <x-finance::icon name="alert" />
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
{{-- L'interrupteur de theme. Le choix est retenu par navigateur : c'est un
     confort de lecture, pas un reglage de l'etablissement. --}}
<script>
    (function () {
        'use strict';

        var racine = document.documentElement;

        function sombreActuellement() {
            var pose = racine.getAttribute('data-theme');

            if (pose === 'dark' || pose === 'light') {
                return pose === 'dark';
            }

            return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
        }

        document.querySelectorAll('[data-theme-switch]').forEach(function (bouton) {
            bouton.addEventListener('click', function () {
                var vers = sombreActuellement() ? 'light' : 'dark';

                racine.setAttribute('data-theme', vers);

                try {
                    localStorage.setItem('finance.theme', vers);
                } catch (e) {
                    // Rien a retenir : le choix vaut pour cette page.
                }
            });
        });
    })();
</script>
</body>
</html>
