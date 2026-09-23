@extends('finance::layout')

@section('title', 'Paramètres financiers')

@section('content')
    <x-finance::page title="Paramètres financiers"
        sub="Ce que l'établissement règle lui-même : son identité sur les documents, ses délais, sa numérotation et ses catégories de dépenses." />

    <form method="post" action="{{ route('finance.settings.update') }}">
        @csrf

        @foreach ($groups as $group => $settings)
            <x-finance::card :title="$group">
                @if ($group === 'Établissement' && $facilityFromHost)
                    <p class="muted">
                        L'application hôte impose le nom imprimé : « {{ $facilityFromHost }} ».
                        Le nom saisi ici ne sert que si elle cesse de le fournir.
                    </p>
                @endif

                @if ($group === 'Numérotation des pièces')
                    <p class="muted">
                        Le préfixe ne vaut que pour les pièces à venir : celles déjà
                        numérotées gardent le leur, et la séquence de l'année continue.
                    </p>
                @endif

                <div class="row">
                    @foreach ($settings as $key => $meta)
                        @if ($meta['type'] === \Keneya\FinanceCaisse\Services\FinanceSettings::TYPE_CATEGORIES)
                            <label style="flex-basis:100%">
                                {{ $meta['label'] }}
                                <textarea name="settings[{{ $key }}]" rows="8">{{ old('settings.'.$key, $values[$key]) }}</textarea>
                                @isset($meta['help']) <span class="help">{{ $meta['help'] }}</span> @endisset
                            </label>
                        @elseif ($meta['type'] === \Keneya\FinanceCaisse\Services\FinanceSettings::TYPE_INT)
                            <label>
                                {{ $meta['label'] }}
                                <input name="settings[{{ $key }}]" inputmode="numeric"
                                       value="{{ old('settings.'.$key, $values[$key]) }}" required>
                                @isset($meta['help']) <span class="help">{{ $meta['help'] }}</span> @endisset
                            </label>
                        @else
                            <label>
                                {{ $meta['label'] }}
                                <input name="settings[{{ $key }}]" value="{{ old('settings.'.$key, $values[$key]) }}" required>
                                @isset($meta['help']) <span class="help">{{ $meta['help'] }}</span> @endisset
                            </label>
                        @endif
                    @endforeach
                </div>
            </x-finance::card>
        @endforeach

        <x-finance::card>
            <div class="actions">
                <button type="submit"><x-finance::icon name="check" /> Enregistrer les paramètres</button>
            </div>
            <p class="muted">
                Un paramètre ramené à la valeur du fichier de configuration cesse d'être
                enregistré : l'établissement revient au défaut sans rien effacer à la main.
                Chaque changement est inscrit au journal d'audit.
            </p>
        </x-finance::card>
    </form>
@endsection
