{{--
    Carte : le conteneur de base de toutes les pages.

    - `title` / `hint` remplissent l'en-tête ;
    - le slot `actions` pose des boutons à droite de l'en-tête ;
    - `flush` colle le contenu aux bords (pour un tableau pleine largeur).
--}}
@props(['title' => null, 'hint' => null, 'flush' => false, 'actions' => null])

<section {{ $attributes->merge(['class' => 'card']) }}>
    @if ($title !== null)
        <div class="hd">
            <h2>{{ $title }}</h2>
            @if ($hint) <span class="hint">{{ $hint }}</span> @endif
            @if ($actions) <div class="acts">{{ $actions }}</div> @endif
        </div>
    @endif

    @if ($flush)
        {{ $slot }}
    @else
        <div class="bd">{{ $slot }}</div>
    @endif
</section>
