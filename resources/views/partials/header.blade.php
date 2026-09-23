@php
    use Keneya\Pharmacie\Services\AlertCenter;
    use Keneya\Pharmacie\Support\Profile;

    $viewer = auth()->user();

    // Ce qui attend un geste de cette personne, et d'elle seule.
    $alertes = app(AlertCenter::class)->count($viewer);
@endphp

<header class="topbar">
    <label for="pha-nav" class="burger" title="Menu" aria-label="Ouvrir le menu"><x-pharmacie::icon name="menu" /></label>

    <span class="spacer"></span>

    <a class="bell" href="{{ route('pharmacie.alerts.index') }}"
       title="{{ $alertes === 0 ? 'Aucune alerte' : $alertes.' alerte(s) a traiter' }}">
        <x-pharmacie::icon name="bell" />
        @if ($alertes > 0)
            <span class="dot">{{ $alertes > 9 ? '9+' : $alertes }}</span>
        @endif
        <span class="sr">{{ $alertes === 0 ? 'Aucune alerte' : $alertes.' alerte(s) a traiter' }}</span>
    </a>


    <span class="muted" style="display:flex;align-items:center;gap:.4375rem;font-size:.8438rem">
        <x-pharmacie::icon name="calendrier" />
        {{ ucfirst(now()->translatedFormat('D d M Y')) }}
    </span>

    @if ($viewer)
        <span class="sep"></span>

        <div class="who">
            <span class="av">{{ Profile::initials($viewer) }}</span>
            <span class="id">
                <strong>{{ $viewer->name ?? '' }}</strong>
                @if ($label = Profile::roleLabel($viewer)) <small>{{ $label }}</small> @endif
            </span>
        </div>
    @endif
</header>
