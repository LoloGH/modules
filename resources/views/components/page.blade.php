{{-- En-tête de page : titre, sous-titre, et actions principales à droite. --}}
@props(['title', 'sub' => null, 'actions' => null])

<header class="page">
    <div class="titles">
        <h1>{{ $title }}</h1>
        @if ($sub) <p class="sub">{{ $sub }}</p> @endif
    </div>
    @if ($actions) <div class="acts">{{ $actions }}</div> @endif
</header>
