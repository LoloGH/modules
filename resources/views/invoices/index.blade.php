@extends('finance::layout')

@section('title', 'Factures')

@section('content')
    <x-finance::page title="Factures" sub="Ce que les patients doivent, ce qu'ils ont réglé, et ce qui reste.">
        <x-slot:actions>
            @can('finance.invoices.create')
                <a class="btn" href="{{ route('finance.invoices.create') }}"><x-finance::icon name="plus" /> Nouvelle facture</a>
            @endcan
        </x-slot:actions>
    </x-finance::page>

    <div class="kpis">
        <x-finance::kpi label="Montant facturé" icon="facture" tone="blue" :value="$money($stats['total'])" foot="Hors factures annulées ou remboursées" />
        <x-finance::kpi label="Payé par les patients" icon="recette" tone="green" :value="$money($stats['paid'])" />
        <x-finance::kpi label="Solde à encaisser" icon="creance" tone="red" :value="$money(max(0, $stats['due'] - $stats['paid']))" foot="Part des patients ; la part des assureurs se suit dans Assurances" />
    </div>

    <nav class="tabs">
        @foreach ($tabs as $key => $label)
            @php ($n = $key === 'tous' ? array_sum($counts) : ($counts[$key] ?? 0))
            <a href="{{ route('finance.invoices.index', array_filter(['statut' => $key === 'tous' ? null : $key, 'q' => $search])) }}"
               class="{{ $status === $key ? 'on' : '' }}">{{ $label }} <span class="muted">({{ $n }})</span></a>
        @endforeach
    </nav>

    <x-finance::card flush>
        <div class="bd">
            <form method="get" action="{{ route('finance.invoices.index') }}" class="inline">
                @if ($status !== 'tous') <input type="hidden" name="statut" value="{{ $status }}"> @endif
                <input name="q" value="{{ $search }}" placeholder="N° de facture, patient, identifiant…" aria-label="Rechercher">
                <button type="submit" class="ghost sm">Rechercher</button>
                @if ($search)
                    <a class="btn ghost sm" href="{{ route('finance.invoices.index', array_filter(['statut' => $status === 'tous' ? null : $status])) }}">Effacer</a>
                @endif
            </form>
        </div>

        @if ($invoices->isEmpty())
            <div class="bd">
                <x-finance::empty title="Aucune facture dans cette liste" icon="facture">
                    Les factures émises apparaîtront ici, avec leur solde.
                </x-finance::empty>
            </div>
        @else
            <div class="tw">
                <table class="stack wide">
                    <thead>
                    <tr>
                        <th>N°</th><th>Patient</th><th>Date</th>
                        <th class="num">Montant</th><th class="num">Payé</th><th class="num">Solde</th>
                        <th>Statut</th><th></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($invoices as $invoice)
                        <tr>
                            <td data-l="N°" class="mono">{{ $invoice->number }}</td>
                            <td data-l="Patient" class="strong">
                                {{ $invoice->patient_name ?? '-' }}
                                @if ($invoice->patient_id) <span class="sub mono">{{ $invoice->patient_id }}</span> @endif
                                @if ($invoice->insurer_id) <span class="sub">Assurance {{ $invoice->coverage_rate }} %</span> @endif
                            </td>
                            <td data-l="Date">{{ $invoice->created_at?->format('d/m/Y') }}</td>
                            <td data-l="Montant" class="num strong">{{ $money($invoice->total) }}</td>
                            <td data-l="Payé" class="num">{{ $money($invoice->paid) }}</td>
                            <td data-l="Solde" class="num {{ $invoice->balance() > 0 ? 'strong' : 'muted' }}">{{ $money($invoice->balance()) }}</td>
                            <td data-l="Statut"><span class="badge {{ $invoice->statusTone() }}">{{ $invoice->statusLabel() }}</span></td>
                            <td data-l="" class="acts">
                                <a class="btn ghost sm" href="{{ route('finance.invoices.show', $invoice) }}">Voir</a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            @if ($invoices->hasPages())
                <div class="bd pager">
                    @if ($invoices->previousPageUrl()) <a class="btn ghost sm" href="{{ $invoices->previousPageUrl() }}">Précédentes</a> @endif
                    <span class="muted">Page {{ $invoices->currentPage() }} sur {{ $invoices->lastPage() }}</span>
                    @if ($invoices->nextPageUrl()) <a class="btn ghost sm" href="{{ $invoices->nextPageUrl() }}">Suivantes</a> @endif
                </div>
            @endif
        @endif
    </x-finance::card>
@endsection
