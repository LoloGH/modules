@extends('finance::layout')

@section('title', 'Paiements')

@section('content')
    <x-finance::page title="Paiements"
        sub="Les encaissements {{ $filters->periodLabel() }}{{ $scoped ? ', de vos sessions' : ', de toutes les caisses' }}." />

    <div class="kpis">
        <x-finance::kpi label="Total encaissé" icon="recette" tone="green" :value="$money($stats['total'])" foot="Encaissements valides de la période" />
        <x-finance::kpi label="Encaissements" icon="paiement" tone="blue" :value="(string) $stats['count']" />
        <x-finance::kpi label="Annulés" icon="alert" tone="red" :value="(string) $stats['cancelled']" foot="Hors du total" />
    </div>

    <x-finance::card flush>
        <div class="bd">
            <form method="get" action="{{ route('finance.ledger.payments') }}">
                <div class="row">
                    <label>Du <input type="date" name="du" value="{{ $filters->from->toDateString() }}"></label>
                    <label>Au <input type="date" name="au" value="{{ $filters->to->toDateString() }}"></label>
                    <label>Moyen de paiement
                        <select name="moyen">
                            <option value="">Tous</option>
                            @foreach ($methods as $method)
                                <option value="{{ $method->id }}" @selected($filters->methodId === $method->id)>{{ $method->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Statut
                        <select name="statut">
                            <option value="tous">Tous</option>
                            <option value="valid" @selected($filters->status === 'valid')>Valides</option>
                            <option value="cancelled" @selected($filters->status === 'cancelled')>Annulés</option>
                        </select>
                    </label>
                    <label>Recherche
                        <input name="q" value="{{ $filters->search }}" placeholder="N°, patient, référence, facture…">
                    </label>
                </div>
                <div class="actions">
                    <button type="submit" class="sm">Filtrer</button>
                    <a class="btn ghost sm" href="{{ route('finance.ledger.payments') }}">Réinitialiser</a>
                </div>
            </form>

            @if ($byMethod->isNotEmpty())
                <p class="muted" style="margin:.75rem 0 0">
                    Par moyen :
                    @foreach ($byMethod as $methodId => $total)
                        <span class="badge muted">{{ $methods->firstWhere('id', $methodId)?->name ?? '-' }} · {{ $money($total) }}</span>
                    @endforeach
                </p>
            @endif
        </div>

        @if ($payments->isEmpty())
            <div class="bd">
                <x-finance::empty title="Aucun encaissement pour ces critères" icon="paiement">
                    Élargissez la période ou retirez un filtre.
                </x-finance::empty>
            </div>
        @else
            <div class="tw">
                <table class="stack wide">
                    <thead>
                    <tr>
                        <th>N°</th><th>Date</th><th>Patient</th><th>Objet</th><th>Moyen</th><th>Référence</th>
                        <th>Facture</th><th>Statut</th><th class="num">Montant</th><th></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($payments as $payment)
                        <tr class="{{ $payment->isCancelled() ? 'cancelled' : '' }}">
                            <td data-l="N°" class="mono">{{ $payment->number }}</td>
                            <td data-l="Date">{{ $payment->created_at?->format('d/m/Y H:i') }}
                                <span class="sub">{{ $payment->session?->register?->name }}</span></td>
                            <td data-l="Patient">
                                {{ $payment->patient_name ?? '-' }}
                                @if ($payment->patient_id) <span class="sub mono">{{ $payment->patient_id }}</span> @endif
                            </td>
                            <td data-l="Objet">{{ $payment->act?->name ?? $payment->description ?? '-' }}</td>
                            <td data-l="Moyen">{{ $payment->method?->name }}</td>
                            <td data-l="Référence" class="mono">{{ $payment->reference ?? '-' }}</td>
                            <td data-l="Facture">
                                @if ($payment->invoice)
                                    @can('finance.invoices.view')
                                        <a href="{{ route('finance.invoices.show', $payment->invoice) }}">{{ $payment->invoice->number }}</a>
                                    @else
                                        {{ $payment->invoice->number }}
                                    @endcan
                                @else - @endif
                            </td>
                            <td data-l="Statut">
                                <span class="badge {{ $payment->isCancelled() ? 'danger' : 'ok' }}">{{ $payment->isCancelled() ? 'Annulé' : 'Valide' }}</span>
                            </td>
                            <td data-l="Montant" class="num strong">{{ $money($payment->amount) }}</td>
                            <td data-l="" class="acts">
                                <a class="btn ghost sm" target="_blank" rel="noopener" href="{{ route('finance.cash.payments.receipt', $payment) }}">Reçu</a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            @if ($payments->hasPages())
                <div class="bd pager">
                    @if ($payments->previousPageUrl()) <a class="btn ghost sm" href="{{ $payments->previousPageUrl() }}">Précédents</a> @endif
                    <span class="muted">Page {{ $payments->currentPage() }} sur {{ $payments->lastPage() }}</span>
                    @if ($payments->nextPageUrl()) <a class="btn ghost sm" href="{{ $payments->nextPageUrl() }}">Suivants</a> @endif
                </div>
            @endif
        @endif
    </x-finance::card>
@endsection
