@php
    use Keneya\Pharmacie\Support\Profile;

    $viewer = auth()->user();
@endphp

<header class="topbar">
    <label for="pha-nav" class="burger" title="Menu" aria-label="Ouvrir le menu"><x-pharmacie::icon name="menu" /></label>

    <span class="spacer"></span>


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
