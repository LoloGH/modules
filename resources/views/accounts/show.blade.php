@extends('finance::layout')

@section('title', $patientName ?? $patientId)

@section('content')
    <a class="back" href="{{ route('finance.accounts.index') }}">
        <x-finance::icon name="retour" /> Retour aux comptes
    </a>

    <x-finance::page :title="$patientName ?? 'Compte patient'" :sub="$patientId" />

    <div class="kpis">
        <x-finance::kpi label="Avances versées" icon="recette" tone="green" :value="$money($summary['deposited'])" />
        <x-finance::kpi label="Déjà utilisé" icon="paiement" tone="blue" :value="$money($summary['used'])" />
        @if ($summary['refunded'] > 0)
            <x-finance::kpi label="Remboursé" icon="depense" tone="red" :value="$money($summary['refunded'])" foot="Rendu au patient" />
        @endif
        <x-finance::kpi label="Solde du compte" icon="caisse" tone="violet" :value="$money($summary['balance'])" foot="Disponible pour un encaissement" />
        <x-finance::kpi label="Reste dû sur factures" icon="creance" tone="amber" :value="$money($outstanding)" />
    </div>

    <x-finance::card title="Relevé du compte" hint="{{ $movements->count() }} mouvement(s)" flush>
        @if ($movements->isEmpty())
            <div class="bd">
                <x-finance::empty title="Aucun mouvement" icon="paiement">
                    Les avances et ce qu'elles paient apparaîtront ici.
                </x-finance::empty>
            </div>
        @else
            <div class="tw">
                <table class="stack wide">
                    <thead>
                    <tr><th>Date</th><th>Type</th><th>Détail</th><th>Moyen</th><th class="num">Montant</th></tr>
                    </thead>
                    <tbody>
                    @foreach ($movements as $movement)
                        @php($item = $movement['model'])
                        <tr class="{{ $item->isCancelled() ? 'cancelled' : '' }}">
                            <td data-l="Date">
                                {{ $item->created_at?->format('d/m/Y H:i') }}
                                <span class="sub mono">{{ $item->number }}</span>
                            </td>
                            <td data-l="Type">
                                <span class="badge {{ $movement['type'] === 'deposit' ? 'ok' : 'info' }}">
                                    {{ $movement['type'] === 'deposit' ? 'Avance' : 'Utilisation' }}
                                </span>
                            </td>
                            <td data-l="Détail">
                                @if ($movement['type'] === 'deposit')
                                    {{ $item->note ?? 'Avance sur compte' }}
                                @else
                                    {{ $item->act?->name ?? $item->description ?? 'Encaissement' }}
                                    @if ($item->invoice)
                                        <span class="sub">Facture {{ $item->invoice->number }}</span>
                                    @endif
                                @endif
                                <span class="sub">{{ $item->session?->register?->name }}</span>
                                @if ($item->isCancelled())
                                    <span class="sub why">Annulé par {{ $item->cancelled_by_name }} : {{ $item->cancellation_reason }}</span>
                                @endif
                            </td>
                            <td data-l="Moyen">{{ $item->method?->name }}</td>
                            <td data-l="Montant" class="num strong">
                                {{ $movement['type'] === 'deposit' ? '+' : '−' }}{{ $money($item->amount) }}
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-finance::card>

    <x-finance::card title="Factures du patient" hint="{{ $invoices->count() }} facture(s)" flush>
        @if ($invoices->isEmpty())
            <div class="bd">
                <x-finance::empty title="Aucune facture" icon="facture">
                    Rien n'a encore été facturé à ce patient.
                </x-finance::empty>
            </div>
        @else
            <div class="tw">
                <table class="stack">
                    <thead>
                    <tr><th>N°</th><th>Date</th><th>Statut</th><th class="num">Total</th><th class="num">Réglé</th><th class="num">Reste dû</th><th></th></tr>
                    </thead>
                    <tbody>
                    @foreach ($invoices as $invoice)
                        <tr>
                            <td data-l="N°" class="mono">{{ $invoice->number }}</td>
                            <td data-l="Date">{{ $invoice->created_at?->format('d/m/Y') }}</td>
                            <td data-l="Statut"><span class="badge {{ $invoice->statusTone() }}">{{ $invoice->statusLabel() }}</span></td>
                            <td data-l="Total" class="num">{{ $money($invoice->total) }}</td>
                            <td data-l="Réglé" class="num">{{ $money($invoice->paid) }}</td>
                            <td data-l="Reste dû" class="num strong">{{ $money($invoice->balance()) }}</td>
                            <td data-l="" class="acts">
                                @can('finance.invoices.view')
                                    <a class="btn ghost sm" href="{{ route('finance.invoices.show', $invoice) }}">Voir</a>
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
