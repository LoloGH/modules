@extends('pharmacie::layout')

@section('title', 'Emplacements')

@section('content')
    <x-pharmacie::page title="Emplacements"
        sub="Pharmacie centrale, réserve, comptoir, services de soins : savoir combien on en a ne suffit pas, il faut savoir où." />

    @can('pharmacie.stock.adjust')
        <x-pharmacie::card title="Nouvel emplacement">
            <form method="post" action="{{ route('pharmacie.stock.locations.store') }}">
                @csrf
                <div class="row">
                    <label>Code <input name="code" value="{{ old('code') }}" placeholder="CENTRALE" required></label>
                    <label>Nom <input name="name" value="{{ old('name') }}" placeholder="Pharmacie centrale" required></label>
                    <label>Type
                        <select name="kind" required>
                            @foreach ($kinds as $key => $label)
                                <option value="{{ $key }}" @selected(old('kind') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
                <div class="actions">
                    <button type="submit"><x-pharmacie::icon name="plus" /> Créer l'emplacement</button>
                </div>
            </form>
        </x-pharmacie::card>
    @endcan

    <x-pharmacie::card title="Emplacements de la pharmacie" hint="{{ $locations->count() }} emplacement(s)" flush>
        @if ($locations->isEmpty())
            <div class="bd">
                <x-pharmacie::empty title="Aucun emplacement" icon="reception">
                    Créez-en un : c'est là que les réceptions entreront.
                </x-pharmacie::empty>
            </div>
        @else
            <div class="tw">
                <table class="stack">
                    <thead><tr><th>Nom</th><th>Code</th><th>Type</th><th class="num">Unités en stock</th><th>État</th>@can('pharmacie.stock.adjust')<th></th>@endcan</tr></thead>
                    <tbody>
                    @foreach ($locations as $row)
                        @php($location = $row['location'])
                        <tr>
                            <td data-l="Nom" class="strong">{{ $location->name }}</td>
                            <td data-l="Code" class="mono">{{ $location->code }}</td>
                            <td data-l="Type">{{ $location->kindLabel() }}</td>
                            <td data-l="Unités" class="num">{{ $row['on_hand'] }}</td>
                            <td data-l="État">
                                <span class="badge {{ $location->is_active ? 'ok' : 'off' }}">{{ $location->is_active ? 'Actif' : 'Désactivé' }}</span>
                            </td>
                            @can('pharmacie.stock.adjust')
                                <td data-l="" class="acts">
                                    <form method="post" action="{{ route('pharmacie.stock.locations.toggle', $location) }}">@csrf
                                        <button type="submit" class="ghost sm">{{ $location->is_active ? 'Désactiver' : 'Activer' }}</button>
                                    </form>
                                </td>
                            @endcan
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-pharmacie::card>
@endsection
