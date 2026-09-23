@extends('pharmacie::layout')

@section('title', 'Dispensations')

@section('content')
    <x-pharmacie::page title="Dispensations"
        sub="Ce qui a été délivré, à qui, de quel lot, par qui.">
        <x-slot:actions>
            @can('pharmacie.dispensing.create')
                <a class="btn" href="{{ route('pharmacie.dispensing.create') }}">
                    <x-pharmacie::icon name="dispensation" /> Délivrer
                </a>
            @endcan
        </x-slot:actions>
    </x-pharmacie::page>

    <div class="kpis">
        <x-pharmacie::kpi label="Dispensations du jour" icon="dispensation" tone="green" :value="(string) $stats['today']" />
        <x-pharmacie::kpi label="Avec reliquat" icon="alert" tone="amber" :value="(string) $stats['partial']" foot="Reste à délivrer" />
    </div>

    <x-pharmacie::card flush>
        <div class="bd">
            <form method="get" action="{{ route('pharmacie.dispensing.index') }}" class="inline">
                <label class="sr" for="q">Recherche</label>
                <input id="q" name="q" value="{{ $search }}" placeholder="N°, patient, ordonnance">
                <label>Filtre
                    <select name="statut">
                        <option value="">Toutes</option>
                        <option value="partielles" @selected($status === 'partielles')>Avec reliquat</option>
                        <option value="annulees" @selected($status === 'annulees')>Annulées</option>
                    </select>
                </label>
                <button type="submit" class="ghost sm">Filtrer</button>
            </form>
        </div>

        @if ($dispensations->isEmpty())
            <div class="bd">
                <x-pharmacie::empty title="Aucune dispensation" icon="dispensation">
                    Ce qui sera délivré au comptoir apparaîtra ici.
                </x-pharmacie::empty>
            </div>
        @else
            <div class="tw">
                <table class="stack wide">
                    <thead>
                    <tr><th>N°</th><th>Patient</th><th>Origine</th><th>Date</th><th class="num">Lignes</th><th class="num">Reliquat</th><th class="num">Montant</th><th>État</th><th></th></tr>
                    </thead>
                    <tbody>
                    @foreach ($dispensations as $dispensation)
                        <tr class="{{ $dispensation->isCancelled() ? 'cancelled' : '' }}">
                            <td data-l="N°" class="mono strong">{{ $dispensation->number }}</td>
                            <td data-l="Patient">
                                {{ $dispensation->patient_name ?? '—' }}
                                <span class="sub mono">{{ $dispensation->patient_id }}</span>
                            </td>
                            <td data-l="Origine">
                                {{ $dispensation->sourceLabel() }}
                                @if ($dispensation->prescription_ref) <span class="sub mono">{{ $dispensation->prescription_ref }}</span> @endif
                            </td>
                            <td data-l="Date">{{ $dispensation->dispensed_at?->format('d/m/Y H:i') }}</td>
                            <td data-l="Lignes" class="num">{{ $dispensation->items->count() }}</td>
                            <td data-l="Reliquat" class="num">{{ $dispensation->outstanding }}</td>
                            <td data-l="Montant" class="num strong">{{ $money($dispensation->total) }}</td>
                            <td data-l="État"><span class="badge {{ $dispensation->statusTone() }}">{{ $dispensation->statusLabel() }}</span></td>
                            <td data-l="" class="acts">
                                <a class="btn ghost sm" href="{{ route('pharmacie.dispensing.show', $dispensation) }}">Voir</a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            @if ($dispensations->hasPages())
                <div class="bd pager">
                    @if ($dispensations->previousPageUrl()) <a class="btn ghost sm" href="{{ $dispensations->previousPageUrl() }}">← Précédentes</a> @endif
                    <span class="muted">Page {{ $dispensations->currentPage() }} sur {{ $dispensations->lastPage() }}</span>
                    @if ($dispensations->nextPageUrl()) <a class="btn ghost sm" href="{{ $dispensations->nextPageUrl() }}">Suivantes →</a> @endif
                </div>
            @endif
        @endif
    </x-pharmacie::card>
@endsection
