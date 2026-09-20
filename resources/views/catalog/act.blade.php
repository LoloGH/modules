@extends('finance::layout')

@section('title', $act->name)

@section('content')
    <a class="back" href="{{ route('finance.catalog.acts.index') }}">
        <x-finance::icon name="retour" /> Retour au catalogue
    </a>

    <x-finance::page
        :title="$act->name"
        sub="{{ $act->code }} · {{ $act->center?->name ?? 'Sans centre analytique' }}{{ $act->dme_service_id ? ' · service DME n° '.$act->dme_service_id : '' }}">
        <x-slot:actions>
            <span class="badge {{ $act->is_active ? 'ok' : 'off' }}">{{ $act->is_active ? 'Acte actif' : 'Acte désactivé' }}</span>
        </x-slot:actions>
    </x-finance::page>

    @if ($act->description)
        <x-finance::card>{{ $act->description }}</x-finance::card>
    @endif

    <div class="cols wide">
        <x-finance::card title="Tarifs" hint="{{ $tariffs->count() }} ligne(s)" flush>
            @if ($tariffs->isEmpty())
                <div class="bd">
                    <x-finance::empty title="Aucun tarif fixé pour cet acte" icon="acte">
                        Fixez son tarif standard pour qu'il puisse être facturé.
                    </x-finance::empty>
                </div>
            @else
                <div class="tw">
                    <table class="stack">
                        <thead>
                        <tr><th>Contexte</th><th>Libellé</th><th class="num">Montant</th><th>Applicable le</th><th>État</th><th>Fixé le</th></tr>
                        </thead>
                        <tbody>
                        @foreach ($tariffs as $tariff)
                            <tr>
                                <td data-l="Contexte" class="strong">{{ $tariff->kind }}</td>
                                <td data-l="Libellé">{{ $tariff->label ?? '—' }}</td>
                                <td data-l="Montant" class="num strong">{{ $money((int) $tariff->amount) }}</td>
                                <td data-l="Applicable le">{{ $tariff->effective_from?->format('d/m/Y') }}</td>
                                <td data-l="État">
                                    @if ($tariff->is_active)
                                        <span class="badge ok"><span class="pt"></span>Actif</span>
                                    @else
                                        <span class="badge off">Historique</span>
                                    @endif
                                </td>
                                <td data-l="Fixé le" class="mono">{{ $tariff->created_at?->format('d/m/Y H:i') }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-finance::card>

        @can('finance.tariffs.manage')
            <x-finance::card title="Fixer ou changer un tarif">
                <p class="muted">
                    Un changement ne modifie pas l'ancienne ligne : il en crée une nouvelle
                    et désactive la précédente.
                </p>
                <form method="post" action="{{ route('finance.catalog.tariffs.store', $act) }}">
                    @csrf
                    <label>Contexte
                        <input name="kind" value="{{ old('kind', $defaultKind) }}" required>
                        <span class="help">ex. standard, conventionne</span>
                    </label>
                    <label>Libellé
                        <input name="label" value="{{ old('label') }}" placeholder="ex. Tarif conventionné">
                    </label>
                    <label>Montant
                        <input name="amount" class="money" inputmode="numeric" value="{{ old('amount') }}" placeholder="0" required>
                        <span class="help">En FCFA, sans décimale.</span>
                    </label>
                    <label>Applicable à partir du
                        <input name="effective_from" type="date" value="{{ old('effective_from', now()->toDateString()) }}">
                    </label>
                    <div class="actions">
                        <button type="submit" class="block"><x-finance::icon name="check" /> Enregistrer le tarif</button>
                    </div>
                </form>
            </x-finance::card>
        @endcan
    </div>
@endsection
