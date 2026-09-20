@extends('finance::layout')

@section('title', 'Actes et prestations')

@section('content')
    <h1>Actes et prestations</h1>
    <p class="muted">Le catalogue de ce qui se facture. Le prix vit dans les tarifs : il change sans réécrire l'acte.</p>

    @can('finance.catalog.manage')
        <section class="card">
            <h2>Nouvel acte</h2>
            <form method="post" action="{{ route('finance.catalog.acts.store') }}">
                @csrf
                <label>Code (ex. CONS-GEN) <input name="code" value="{{ old('code') }}" required></label>
                <label>Nom (ex. Consultation générale) <input name="name" value="{{ old('name') }}" required></label>
                <label>Centre analytique
                    <select name="analytic_center_id">
                        <option value="">— Aucun</option>
                        @foreach ($centers as $center)
                            <option value="{{ $center->id }}" @selected((string) old('analytic_center_id') === (string) $center->id)>{{ $center->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label>Identifiant du service DME (facultatif, pour les rapprochements)
                    <input name="dme_service_id" inputmode="numeric" value="{{ old('dme_service_id') }}">
                </label>
                <label>Description <input name="description" value="{{ old('description') }}"></label>
                <button type="submit">Créer l'acte</button>
            </form>
        </section>
    @endcan

    <section class="card">
        <h2>Catalogue</h2>
        @if ($acts->isEmpty())
            <p class="muted">Aucun acte. Créez ceux que l'établissement facture.</p>
        @else
            <table>
                <thead><tr><th>Code</th><th>Nom</th><th>Centre</th><th class="num">Tarif standard</th><th>État</th><th></th></tr></thead>
                <tbody>
                @foreach ($acts as $act)
                    <tr>
                        <td>{{ $act->code }}</td>
                        <td>{{ $act->name }}</td>
                        <td>{{ $act->center?->name ?? '—' }}</td>
                        <td class="num">{{ $act->standardTariff === null ? 'Non fixé' : $money((int) $act->standardTariff->amount) }}</td>
                        <td>{{ $act->is_active ? 'Actif' : 'Désactivé' }}</td>
                        <td>
                            <a href="{{ route('finance.catalog.acts.show', $act) }}">Voir</a>
                            @can('finance.catalog.manage')
                                <form method="post" action="{{ route('finance.catalog.acts.toggle', $act) }}">@csrf
                                    <button type="submit" class="secondary">{{ $act->is_active ? 'Désactiver' : 'Activer' }}</button>
                                </form>
                            @endcan
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </section>
@endsection
