{{--
    Carte de mesure : icône, intitulé, valeur, information secondaire.
    `tone` donne la couleur (green, red, blue, violet, amber).
--}}
@props(['label', 'value', 'icon' => 'point', 'tone' => '', 'unit' => null, 'foot' => null])

<div class="kpi {{ $tone }}">
    <div class="ico {{ $tone }}"><x-finance::icon :name="$icon" /></div>
    <div class="body">
        <div class="label">{{ $label }}</div>
        <div class="value">{{ $value }}@if ($unit)<span class="cur">{{ $unit }}</span>@endif</div>
        @if ($foot) <div class="foot">{{ $foot }}</div> @endif
    </div>
</div>
