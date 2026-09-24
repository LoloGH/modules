@php
    use Keneya\FinanceCaisse\Support\Navigation;

    $viewer = auth()->user();

    $allowed = static fn (array $item): bool => $item['can'] === null || (bool) $viewer?->can($item['can']);
    $lit = static fn (array $item): bool => collect($item['match'])->contains(fn (string $m): bool => request()->routeIs($m));

    $sections = [];

    foreach (Navigation::sections() as $section) {
        $items = array_values(array_filter($section['items'], $allowed));

        if ($items !== []) {
            $sections[] = ['section' => $section['section'], 'items' => $items];
        }
    }

    // Les entrées « bientôt » montrent la cible du module ; elles n'ont pas à
    // s'afficher pour quelqu'un qui n'a accès à aucun écran réel.
    $hasRealEntry = collect($sections)
        ->flatMap(fn (array $section): array => $section['items'])
        ->contains(fn (array $item): bool => $item['route'] !== null && $item['can'] !== null);
@endphp

<aside class="sidebar">
    <a class="brand" href="{{ route('finance.home') }}">
        <x-finance::logo />
        <span>
            <span class="name">Keneya <em>Finance</em></span>
        </span>
    </a>

    @if ($hasRealEntry)
        <nav class="nav">
            @foreach ($sections as $section)
                @if ($section['section'] !== '')
                    <div class="group">{{ $section['section'] }}</div>
                @endif

                @foreach ($section['items'] as $item)
                    @if ($item['route'] === null)
                        <span class="soon">
                            <x-finance::icon :name="$item['icon']" />
                            {{ $item['label'] }}
                            <span class="chip">bientôt</span>
                        </span>
                    @else
                        <a href="{{ route($item['route']) }}"
                           class="{{ $lit($item) ? 'on' : '' }}"
                           @if ($lit($item)) aria-current="page" @endif>
                            <x-finance::icon :name="$item['icon']" />
                            {{ $item['label'] }}
                        </a>
                    @endif
                @endforeach
            @endforeach
        </nav>
    @endif

    <div class="version">Version {{ config('finance.version') }}</div>
</aside>
