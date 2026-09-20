@extends('finance::layout')

@section('title', $act->name)

@section('content')
    <h1>{{ $act->name }}</h1>
    <p class="muted">
        {{ $act->code }} · {{ $act->center?->name ?? 'Sans centre analytique' }} ·
        {{ $act->is_active ? 'Acte actif' : 'Acte désactivé' }}
        @if ($act->dme_service_id) · service DME n° {{ $act->dme_service_id }} @endif
    </p>
    @if ($act->description)
        <p>{{ $act->description }}</p>
    @endif

    @can('finance.tariffs.manage')
        <section class="card">
            <h2>Fixer ou changer un tarif</h2>
            <p class="muted">Un changement ne modifie pas l'ancienne ligne : il en crée une nouvelle et désactive la précédente.</p>
            <form method="post" action="{{ route('finance.catalog.tariffs.store', $act) }}">
                @csrf
                <label>Contexte (ex. standard, conventionne)
                    <input name="kind" value="{{ old('kind', $defaultKind) }}" required>
                </label>
                <label>Libellé (facultatif, ex. Tarif conventionné)
                    <input name="label" value="{{ old('label') }}">
                </label>
                <label>Montant (FCFA)
                    <input name="amount" inputmode="numeric" value="{{ old('amount') }}" required>
                </label>
                <label>Applicable à partir du
                    <input name="effective_from" type="date" value="{{ old('effective_from', now()->toDateString()) }}">
                </label>
                <button type="submit">Enregistrer le tarif</button>
            </form>
        </section>
    @endcan

    <section class="card">
        <h2>Tarifs</h2>
        @if ($tariffs->isEmpty())
            <p class="muted">Aucun tarif fixé pour cet acte.</p>
        @else
            <table>
                <thead><tr><th>Contexte</th><th>Libellé</th><th class="num">Montant</th><th>Applicable le</th><th>État</th><th>Fixé le</th></tr></thead>
                <tbody>
                @foreach ($tariffs as $tariff)
                    <tr>
                        <td>{{ $tariff->kind }}</td>
                        <td>{{ $tariff->label ?? '—' }}</td>
                        <td class="num">{{ $money((int) $tariff->amount) }}</td>
                        <td>{{ $tariff->effective_from?->format('d/m/Y') }}</td>
                        <td>
                            @if ($tariff->is_active)
                                <span class="badge open">Actif</span>
                            @else
                                <span class="muted">Historique</span>
                            @endif
                        </td>
                        <td>{{ $tariff->created_at?->format('d/m/Y H:i') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <p><a href="{{ route('finance.catalog.acts.index') }}">Retour au catalogue</a></p>
@endsection
