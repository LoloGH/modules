@extends('finance::print.layout')

@section('title', 'Reçu '.$payment->number)
@section('back', $payment->invoice_id ? route('finance.invoices.show', $payment->invoice_id) : route('finance.cash.sessions.show', $payment->cash_session_id))

@section('paper')
    <div class="paper ticket">
        @if ($payment->isCancelled())
            <div class="stamp">ANNULÉ</div>
        @endif

        <div class="head">
            @include('finance::print._facility')
            <div class="doc">
                <h1>REÇU D'ENCAISSEMENT</h1>
                <div class="num">{{ $payment->number }}</div>
                <div class="muted">{{ $payment->created_at?->format('d/m/Y à H:i') }}</div>
            </div>
        </div>

        <div class="amount">{{ number_format((int) $payment->amount, 0, ',', ' ') }} FCFA</div>

        <dl class="rows">
            @if ($payment->patient_name) <div><dt>Patient</dt><dd>{{ $payment->patient_name }}</dd></div> @endif
            @if ($payment->patient_id) <div><dt>Identifiant</dt><dd>{{ $payment->patient_id }}</dd></div> @endif
            <div><dt>Objet</dt><dd>{{ $payment->act?->name ?? $payment->description ?? 'Encaissement' }}</dd></div>
            @if ($payment->invoice) <div><dt>Facture</dt><dd>{{ $payment->invoice->number }}</dd></div> @endif
            <div><dt>Moyen</dt><dd>{{ $payment->method?->name }}</dd></div>
            @if ($payment->reference) <div><dt>Référence</dt><dd>{{ $payment->reference }}</dd></div> @endif
            <div><dt>Caisse</dt><dd>{{ $payment->session?->register?->name }}</dd></div>
            <div><dt>Caissier</dt><dd>{{ $payment->session?->cashier_name }}</dd></div>
            @if ($payment->invoice)
                <div><dt>Reste à payer</dt><dd>{{ number_format($payment->invoice->balance(), 0, ',', ' ') }} FCFA</dd></div>
            @endif
        </dl>

        @if ($payment->isCancelled())
            <p class="foot" style="color:var(--danger)">Annulé le {{ $payment->cancelled_at?->format('d/m/Y H:i') }} : {{ $payment->cancellation_reason }}</p>
        @endif

        <p class="foot">Merci. Conservez ce reçu.</p>
    </div>
@endsection
