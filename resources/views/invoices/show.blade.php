@extends('finance::layout')

@section('title', 'Facture '.$invoice->number)

@section('content')
    <a class="back" href="{{ route('finance.invoices.index') }}"><x-finance::icon name="retour" /> Retour aux factures</a>

    <x-finance::page
        title="Facture {{ $invoice->number }}"
        sub="{{ $invoice->patient_name ?? 'Patient non nommé' }}{{ $invoice->patient_id ? ' · '.$invoice->patient_id : '' }} · Émise le {{ $invoice->created_at?->format('d/m/Y H:i') }}{{ $invoice->created_by_name ? ' par '.$invoice->created_by_name : '' }}">
        <x-slot:actions>
            <span class="badge {{ $invoice->statusTone() }}">{{ $invoice->statusLabel() }}</span>
        </x-slot:actions>
    </x-finance::page>

    <div class="kpis">
        <x-finance::kpi label="Montant" icon="facture" tone="blue" :value="$money($invoice->total)" />
        <x-finance::kpi label="Payé" icon="recette" tone="green" :value="$money($invoice->paid)" />
        <x-finance::kpi label="Solde" icon="creance" tone="red" :value="$money($invoice->balance())" />
    </div>

    @if ($invoice->status === \Keneya\FinanceCaisse\Models\Invoice::STATUS_CANCELLED)
        <p class="flash err">
            Annulée le {{ $invoice->cancelled_at?->format('d/m/Y H:i') }} par {{ $invoice->cancelled_by_name ?? '—' }} : {{ $invoice->cancellation_reason }}
        </p>
    @endif

    <div class="cols wide">
        <div>
            <x-finance::card title="Lignes" flush>
                <div class="tw">
                    <table class="stack">
                        <thead><tr><th>Acte ou prestation</th><th class="num">Quantité</th><th class="num">Prix unitaire</th><th class="num">Montant</th></tr></thead>
                        <tbody>
                        @foreach ($invoice->lines as $line)
                            <tr>
                                <td data-l="Acte" class="strong">{{ $line->label }}</td>
                                <td data-l="Quantité" class="num">{{ $line->quantity }}</td>
                                <td data-l="Prix unitaire" class="num">{{ $money($line->unit_price) }}</td>
                                <td data-l="Montant" class="num strong">{{ $money($line->amount) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($invoice->note)
                    <div class="bd muted">Note : {{ $invoice->note }}</div>
                @endif
            </x-finance::card>

            <x-finance::card title="Encaissements" hint="{{ $invoice->payments->count() }} au total" flush>
                @if ($invoice->payments->isEmpty())
                    <div class="bd">
                        <x-finance::empty title="Aucun encaissement" icon="recette">Rien n'a encore été réglé sur cette facture.</x-finance::empty>
                    </div>
                @else
                    <div class="tw">
                        <table class="stack">
                            <thead><tr><th>N°</th><th>Date</th><th>Moyen</th><th>Caisse</th><th>Statut</th><th class="num">Montant</th></tr></thead>
                            <tbody>
                            @foreach ($invoice->payments as $payment)
                                <tr>
                                    <td data-l="N°" class="mono">{{ $payment->number }}</td>
                                    <td data-l="Date">{{ $payment->created_at?->format('d/m/Y H:i') }}</td>
                                    <td data-l="Moyen">{{ $payment->method?->name }}@if ($payment->reference) <span class="sub">{{ $payment->reference }}</span>@endif</td>
                                    <td data-l="Caisse">{{ $payment->session?->register?->name }}</td>
                                    <td data-l="Statut">
                                        <span class="badge {{ $payment->isCancelled() ? 'off' : 'ok' }}">{{ $payment->isCancelled() ? 'Annulé' : 'Valide' }}</span>
                                    </td>
                                    <td data-l="Montant" class="num strong">{{ $money($payment->amount) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-finance::card>
        </div>

        <div>
            @if ($invoice->canBePaid())
                @can('finance.payments.create')
                    <x-finance::card title="Encaisser">
                        @if ($openSessions->isEmpty())
                            <p class="muted">Ouvrez d'abord votre session de caisse pour encaisser cette facture.</p>
                            <a class="btn" href="{{ route('finance.cash.index') }}"><x-finance::icon name="caisse" /> Ouvrir ma session</a>
                        @else
                            <p class="muted">Solde à encaisser : <strong>{{ $money($invoice->balance()) }}</strong>. Un règlement partiel est possible.</p>
                            @foreach ($openSessions as $session)
                                <a class="btn" href="{{ route('finance.cash.sessions.show', ['session' => $session, 'facture' => $invoice->id]) }}#encaisser">
                                    <x-finance::icon name="recette" /> Encaisser dans {{ $session->register->name }}
                                </a>
                            @endforeach
                        @endif
                    </x-finance::card>
                @endcan
            @endif

            @if (! $invoice->isClosed())
                @can('finance.invoices.cancel')
                    <x-finance::card title="Annuler la facture">
                        @if ($invoice->paid > 0)
                            <p class="muted" style="margin:0">Cette facture a des encaissements : annulez-les d'abord pour pouvoir l'annuler.</p>
                        @else
                            <form method="post" action="{{ route('finance.invoices.cancel', $invoice) }}">
                                @csrf
                                <label>Motif <input name="reason" required placeholder="ex. Facture émise par erreur"></label>
                                <div class="actions"><button type="submit" class="ghost">Annuler la facture</button></div>
                            </form>
                        @endif
                    </x-finance::card>
                @endcan
            @endif
        </div>
    </div>
@endsection
