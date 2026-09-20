{{--
    Marque de Keneya Finance : le « K » sur carré arrondi, en SVG dans la page.
    Le dégradé porte un identifiant unique pour que plusieurs marques sur la
    même page ne se marchent pas dessus.
--}}
@props(['id' => 'kf'])

<svg {{ $attributes->merge(['class' => 'mark']) }} viewBox="0 0 40 40" role="img" aria-label="Keneya Finance">
    <defs>
        <linearGradient id="{{ $id }}-g" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0%" stop-color="#3b82f6"/>
            <stop offset="100%" stop-color="#1d4ed8"/>
        </linearGradient>
    </defs>
    <rect width="40" height="40" rx="11" fill="url(#{{ $id }}-g)"/>
    <path d="M14 10.5v19M14 20.4l9.4-9.9M17.6 17l9 12.5" stroke="#fff" stroke-width="3.1" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
</svg>
