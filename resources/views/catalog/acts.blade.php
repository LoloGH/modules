@extends('finance::layout')

@section('title', 'Actes et prestations')

@section('content')
    <x-finance::page
        title="Actes et prestations"
        sub="Le catalogue de ce qui se facture. Le prix vit dans les tarifs : il change sans réécrire l'acte." />

    @can('finance.catalog.manage')
        <x-finance::card title="Nouvel acte">
            <form method="post" action="{{ route('finance.catalog.acts.store') }}">
                @csrf
                <div class="row">
                    <label>Code <input name="code" value="{{ old('code') }}" placeholder="CONS-GEN" required></label>
                    <label>Nom <input name="name" value="{{ old('name') }}" placeholder="Consultation générale" required></label>
                    <label>Centre analytique
                        <select name="analytic_center_id">
                            <option value="">— Aucun</option>
                            @foreach ($centers as $center)
                                <option value="{{ $center->id }}" @selected((string) old('analytic_center_id') === (string) $center->id)>{{ $center->name }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
                <div class="row">
                    <label>Identifiant du service DME
                        <input name="dme_service_id" inputmode="numeric" value="{{ old('dme_service_id') }}">
                        <span class="help">Facultatif, pour les rapprochements avec le dossier médical.</span>
                    </label>
                    <label>Description <input name="description" value="{{ old('description') }}"></label>
                </div>
                <div class="actions">
                    <button type="submit"><x-finance::icon name="plus" /> Créer l'acte</button>
                </div>
            </form>
        </x-finance::card>
    @endcan

    <x-finance::card title="Catalogue" hint="{{ $acts->count() }} acte(s)" flush>
        @if ($acts->isEmpty())
            <div class="bd">
                <x-finance::empty title="Aucun acte" icon="acte">
                    Créez ceux que l'établissement facture, puis fixez leur tarif.
                </x-finance::empty>
            </div>
        @else
            <div class="tw">
                <table class="stack">
                    <thead>
                    <tr><th>Code</th><th>Nom</th><th>Centre</th><th class="num">Tarif standard</th><th>État</th><th></th></tr>
                    </thead>
                    <tbody>
                    @foreach ($acts as $act)
                        <tr>
                            <td data-l="Code" class="mono">{{ $act->code }}</td>
                            <td data-l="Nom" class="strong">
                                <a href="{{ route('finance.catalog.acts.show', $act) }}">{{ $act->name }}</a>
                                @if ($act->description)<span class="sub">{{ $act->description }}</span>@endif
                            </td>
                            <td data-l="Centre">{{ $act->center?->name ?? '—' }}</td>
                            <td data-l="Tarif standard" class="num strong">
                                @if ($act->standardTariff === null)
                                    <span class="muted" style="font-weight:400">Non fixé</span>
                                @else
                                    {{ $money((int) $act->standardTariff->amount) }}
                                @endif
                            </td>
                            <td data-l="État">
                                <span class="badge {{ $act->is_active ? 'ok' : 'off' }}">{{ $act->is_active ? 'Actif' : 'Désactivé' }}</span>
                            </td>
                            <td data-l="" class="acts">
                                <a class="btn ghost sm" href="{{ route('finance.catalog.acts.show', $act) }}">Voir</a>
                                @can('finance.catalog.manage')
                                    <form method="post" action="{{ route('finance.catalog.acts.toggle', $act) }}" style="margin-left:.375rem">@csrf
                                        <button type="submit" class="ghost sm">{{ $act->is_active ? 'Désactiver' : 'Activer' }}</button>
                                    </form>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-finance::card>
@endsection
