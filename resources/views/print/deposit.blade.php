@extends('finance::print.layout')

@section('title', 'Reçu d\'avance '.$deposit->number)
@section('back', route('finance.cash.sessions.show', $deposit->cash_session_id))

@section('paper')
    <div class="paper ticket">
        @if ($deposit->isCancelled())
            <div class="stamp">ANNULÉ</div>
        @endif

        <div class="head">
            @include('finance::print._facility')
            <div class="doc">
                <h1>REÇU D'AVANCE</h1>
                <div class="num">{{ $deposit->number }}</div>
                <div class="muted">{{ $deposit->created_at?->format('d/m/Y à H:i') }}</div>
            </div>
        </div>

        <div class="amount">{{ number_format((int) $deposit->amount, 0, ',', ' ') }} FCFA</div>

        <dl class="rows">
            <div><dt>Patient</dt><dd>{{ $deposit->patient_name ?? '—' }}</dd></div>
            <div><dt>Identifiant</dt><dd>{{ $deposit->patient_id }}</dd></div>
            @if ($deposit->note) <div><dt>Motif</dt><dd>{{ $deposit->note }}</dd></div> @endif
            <div><dt>Moyen</dt><dd>{{ $deposit->method?->name }}</dd></div>
            @if ($deposit->reference) <div><dt>Référence</dt><dd>{{ $deposit->reference }}</dd></div> @endif
            <div><dt>Solde du compte</dt><dd>{{ number_format($balance, 0, ',', ' ') }} FCFA</dd></div>
            <div><dt>Caisse</dt><dd>{{ $deposit->session?->register?->name }}</dd></div>
            <div><dt>Caissier</dt><dd>{{ $deposit->session?->cashier_name }}</dd></div>
        </dl>

        <p class="foot">
            Cette avance reste acquise au patient : elle règlera ses prochains
            actes, au moyen « Compte patient ».
        </p>

        @if ($deposit->isCancelled())
            <p class="foot" style="color:var(--danger)">Annulée le {{ $deposit->cancelled_at?->format('d/m/Y H:i') }} : {{ $deposit->cancellation_reason }}</p>
        @endif

        <div class="signs"><div>Le caissier</div><div>Le patient</div></div>
    </div>
@endsection
