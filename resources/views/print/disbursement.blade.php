@extends('finance::print.layout')

@section('title', 'Bon de décaissement '.$disbursement->number)
@section('back', route('finance.cash.sessions.show', $disbursement->cash_session_id))

@section('paper')
    <div class="paper ticket">
        @if ($disbursement->isCancelled())
            <div class="stamp">ANNULÉ</div>
        @endif

        <div class="head">
            @include('finance::print._facility')
            <div class="doc">
                <h1>BON DE DÉCAISSEMENT</h1>
                <div class="num">{{ $disbursement->number }}</div>
                <div class="muted">{{ $disbursement->created_at?->format('d/m/Y à H:i') }}</div>
            </div>
        </div>

        <div class="amount">{{ number_format((int) $disbursement->amount, 0, ',', ' ') }} FCFA</div>

        <dl class="rows">
            <div><dt>Motif</dt><dd>{{ $disbursement->reason }}</dd></div>
            @if ($disbursement->beneficiary) <div><dt>Bénéficiaire</dt><dd>{{ $disbursement->beneficiary }}</dd></div> @endif
            <div><dt>Moyen</dt><dd>{{ $disbursement->method?->name }}</dd></div>
            @if ($disbursement->reference) <div><dt>Référence</dt><dd>{{ $disbursement->reference }}</dd></div> @endif
            <div><dt>Caisse</dt><dd>{{ $disbursement->session?->register?->name }}</dd></div>
            <div><dt>Caissier</dt><dd>{{ $disbursement->session?->cashier_name }}</dd></div>
        </dl>

        @if ($disbursement->isCancelled())
            <p class="foot" style="color:var(--danger)">Annulé le {{ $disbursement->cancelled_at?->format('d/m/Y H:i') }} : {{ $disbursement->cancellation_reason }}</p>
        @endif

        <div class="signs"><div>Le caissier</div><div>Le bénéficiaire</div></div>
    </div>
@endsection
