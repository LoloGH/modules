@extends('finance::print.layout')

@section('title', 'Facture '.$invoice->number)
@section('back', route('finance.invoices.show', $invoice))

@section('paper')
    @php($fmt = fn (int $n): string => number_format($n, 0, ',', ' ').' FCFA')
    <div class="paper a4">
        @if ($invoice->status === \Keneya\FinanceCaisse\Models\Invoice::STATUS_CANCELLED)
            <div class="stamp">ANNULÉE</div>
        @endif

        <div class="head">
            @include('finance::print._facility')
            <div class="doc">
                <h1>FACTURE</h1>
                <div class="num">{{ $invoice->number }}</div>
                <div class="muted">Émise le {{ $invoice->created_at?->format('d/m/Y à H:i') }}</div>
                <div style="margin-top:.35rem"><span class="badge">{{ $invoice->statusLabel() }}</span></div>
            </div>
        </div>

        <dl class="rows" style="max-width:60%;margin-bottom:1.25rem">
            <div><dt>Patient</dt><dd>{{ $invoice->patient_name ?? '—' }}</dd></div>
            <div><dt>Identifiant</dt><dd>{{ $invoice->patient_id ?? '—' }}</dd></div>
            @if ($invoice->created_by_name)
                <div><dt>Établie par</dt><dd>{{ $invoice->created_by_name }}</dd></div>
            @endif
        </dl>

        <table>
            <thead><tr><th>Acte ou prestation</th><th class="r">Qté</th><th class="r">Prix unitaire</th><th class="r">Montant</th></tr></thead>
            <tbody>
            @foreach ($invoice->lines as $line)
                <tr>
                    <td>{{ $line->label }}</td>
                    <td class="r">{{ $line->quantity }}</td>
                    <td class="r">{{ $fmt($line->unit_price) }}</td>
                    <td class="r">{{ $fmt($line->amount) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>

        <table class="totals">
            <tr class="grand"><td>Total</td><td class="r">{{ $fmt($invoice->total) }}</td></tr>
            <tr><td>Déjà payé</td><td class="r">{{ $fmt($invoice->paid) }}</td></tr>
            <tr><td><strong>Reste à payer</strong></td><td class="r"><strong>{{ $fmt($invoice->balance()) }}</strong></td></tr>
        </table>

        @if ($invoice->payments->isNotEmpty())
            <p class="muted" style="margin-top:1.5rem;margin-bottom:.25rem">Règlements reçus</p>
            <table>
                <thead><tr><th>Reçu</th><th>Date</th><th>Moyen</th><th class="r">Montant</th></tr></thead>
                <tbody>
                @foreach ($invoice->payments as $payment)
                    <tr>
                        <td>{{ $payment->number }}</td>
                        <td>{{ $payment->created_at?->format('d/m/Y H:i') }}</td>
                        <td>{{ $payment->method?->name }}</td>
                        <td class="r">{{ $fmt($payment->amount) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif

        @if ($invoice->note)
            <p style="margin-top:1rem"><span class="muted">Note :</span> {{ $invoice->note }}</p>
        @endif

        <div class="signs"><div>Le patient</div><div>La caisse</div></div>

        <p class="foot">Montants en francs CFA, sans décimale. Document édité le {{ now()->format('d/m/Y à H:i') }}.</p>
    </div>
@endsection
