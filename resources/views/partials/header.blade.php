@php
    use Keneya\FinanceCaisse\Services\AlertCenter;
    use Keneya\FinanceCaisse\Support\Profile;

    $viewer = auth()->user();

    // Ce qui attend un geste de cette personne, et d'elle seule.
    $alertes = app(AlertCenter::class)->count($viewer);
@endphp

<header class="topbar">
    <label for="fin-nav" class="burger" title="Menu" aria-label="Ouvrir le menu"><x-finance::icon name="menu" /></label>

    <span class="spacer"></span>

    <a class="bell" href="{{ route('finance.alerts.index') }}"
       title="{{ $alertes === 0 ? 'Aucune alerte' : $alertes.' alerte(s) à traiter' }}">
        <x-finance::icon name="bell" />
        @if ($alertes > 0)
            <span class="dot">{{ $alertes > 9 ? '9+' : $alertes }}</span>
        @endif
        <span class="sr">{{ $alertes === 0 ? 'Aucune alerte' : $alertes.' alerte(s) à traiter' }}</span>
    </a>

    <span class="muted" style="display:flex;align-items:center;gap:.4375rem;font-size:.8438rem">
        <x-finance::icon name="calendrier" />
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
