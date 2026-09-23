@extends('pharmacie::print.layout')

@section('title', 'Bon de sortie '.$dispensation->number)
@section('back', route('pharmacie.dispensing.show', $dispensation))

@section('paper')
    <div class="paper">
        @if ($dispensation->isCancelled())
            <div class="stamp">ANNULÉE</div>
        @endif

        <div class="head">
            @include('pharmacie::print._facility')
            <div class="doc">
                <h1>BON DE SORTIE</h1>
                <div class="num">{{ $dispensation->number }}</div>
                <div class="muted">{{ $dispensation->dispensed_at?->format('d/m/Y à H:i') }}</div>
            </div>
        </div>

        <dl class="rows">
            <div><dt>Patient</dt><dd>{{ $dispensation->patient_name ?? '—' }}</dd></div>
            <div><dt>Identifiant</dt><dd>{{ $dispensation->patient_id ?? '—' }}</dd></div>
            @if ($dispensation->prescription_ref)
                <div><dt>Ordonnance</dt><dd>{{ $dispensation->prescription_ref }}</dd></div>
            @endif
            <div><dt>Délivré par</dt><dd>{{ $dispensation->dispensed_by_name }}</dd></div>
            <div><dt>Emplacement</dt><dd>{{ $dispensation->location?->name }}</dd></div>
        </dl>

        <table class="lines">
            <thead>
            <tr><th>Produit</th><th>Posologie</th><th class="r">Délivré</th><th>Lot</th><th class="r">Montant</th></tr>
            </thead>
            <tbody>
            @foreach ($dispensation->items as $item)
                <tr>
                    <td>{{ $item->label }}</td>
                    <td>{{ $item->posology ?? '—' }}</td>
                    <td class="r">{{ $item->quantity }}</td>
                    <td>
                        @foreach ($item->batches as $served)
                            {{ $served->batch?->number }}@if (! $loop->last), @endif
                        @endforeach
                    </td>
                    <td class="r">{{ number_format((int) $item->amount, 0, ',', ' ') }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>

        <div class="totals">
            <table>
                <tr><td>Total</td><td class="r">{{ number_format((int) $dispensation->total, 0, ',', ' ') }} FCFA</td></tr>
                @if ($dispensation->outstanding > 0)
                    <tr><td>Reste à délivrer</td><td class="r">{{ $dispensation->outstanding }} unité(s)</td></tr>
                @endif
            </table>
        </div>

        @if ($dispensation->notes)
            <p class="foot">{{ $dispensation->notes }}</p>
        @endif

        <div class="signs"><div>Le pharmacien</div><div>Le patient</div></div>
    </div>
@endsection
