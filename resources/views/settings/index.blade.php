@extends('pharmacie::layout')

@section('title', 'Paramètres de la pharmacie')

@section('content')
    @php
        use Keneya\Pharmacie\Services\PharmacieSettings;
    @endphp

    <x-pharmacie::page title="Paramètres de la pharmacie"
        sub="Ce que l'établissement règle lui-même : son identité sur les documents, ses délais de péremption, ses règles de contrôle renforcé et sa numérotation." />

    <form method="post" action="{{ route('pharmacie.settings.update') }}">
        @csrf

        @foreach ($groups as $group => $settings)
            <x-pharmacie::card :title="$group">
                @if ($group === 'Établissement' && $facilityFromHost)
                    <p class="muted">
                        L'application hôte impose le nom imprimé : « {{ $facilityFromHost }} ».
                        Le nom saisi ici ne sert que si elle cesse de le fournir.
                    </p>
                @endif

                @if ($group === 'Contrôle renforcé')
                    <p class="muted">
                        Ces règles ne s'appliquent qu'aux produits marqués « sous surveillance »
                        sur leur fiche. Elles ne dispensent de rien d'autre : un lot périmé ne se
                        délivre jamais, et cela ne se règle pas ici.
                    </p>
                @endif

                @if ($group === 'Numérotation des pièces')
                    <p class="muted">
                        Le préfixe ne vaut que pour les pièces à venir : celles déjà numérotées
                        gardent le leur, et la séquence de l'année continue.
                    </p>
                @endif

                @if ($group === 'Prévision de réapprovisionnement')
                    <p class="muted">
                        Au rythme de consommation observé, le besoin couvre l'horizon, plus le
                        délai de livraison, plus la marge, moins le stock et ce qui est déjà
                        commandé.
                    </p>
                @endif

                <div class="row">
                    @foreach ($settings as $key => $meta)
                        @if ($meta['type'] === PharmacieSettings::TYPE_BOOL)
                            <div class="checks" style="flex-basis:100%">
                                <label>
                                    {{-- Une case décochée n'est pas envoyée : ce champ caché
                                         fait la différence entre « non » et « sans avis ». --}}
                                    <input type="hidden" name="settings[{{ $key }}]" value="0">
                                    <input type="checkbox" name="settings[{{ $key }}]" value="1"
                                           @checked((bool) old('settings.'.$key, $values[$key]))>
                                    {{ $meta['label'] }}
                                </label>
                                @isset($meta['help']) <span class="help">{{ $meta['help'] }}</span> @endisset
                            </div>
                        @elseif ($meta['type'] === PharmacieSettings::TYPE_INT)
                            <label>
                                {{ $meta['label'] }}
                                <input name="settings[{{ $key }}]" inputmode="numeric"
                                       value="{{ old('settings.'.$key, $values[$key]) }}" required>
                                @isset($meta['help']) <span class="help">{{ $meta['help'] }}</span> @endisset
                            </label>
                        @else
                            <label>
                                {{ $meta['label'] }}
                                <input name="settings[{{ $key }}]"
                                       value="{{ old('settings.'.$key, $values[$key]) }}"
                                       @unless ($meta['optional'] ?? false) required @endunless>
                                @isset($meta['help']) <span class="help">{{ $meta['help'] }}</span> @endisset
                            </label>
                        @endif
                    @endforeach
                </div>
            </x-pharmacie::card>
        @endforeach

        <x-pharmacie::card>
            <div class="actions">
                <button type="submit"><x-pharmacie::icon name="check" /> Enregistrer les paramètres</button>
            </div>
            <p class="muted">
                Un paramètre ramené à la valeur du fichier de configuration cesse d'être
                réglé ici : l'établissement revient au défaut, et le module suivra ce
                défaut s'il change un jour.
            </p>
        </x-pharmacie::card>
    </form>
@endsection
