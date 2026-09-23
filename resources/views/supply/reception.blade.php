@extends('pharmacie::layout')

@section('title', 'Réception '.$reception->number)

@section('content')
    <a class="back" href="{{ route('pharmacie.supply.receptions.index') }}">
        <x-pharmacie::icon name="retour" /> Retour aux réceptions
    </a>

    <x-pharmacie::page :title="'Bon de réception '.$reception->number" :sub="$reception->supplier?->name">
        <x-slot:actions>
            @if ($reception->anomalies) <span class="badge warn">Anomalie signalée</span> @endif
        </x-slot:actions>
    </x-pharmacie::page>

    <div class="cols">
        <x-pharmacie::card title="La livraison">
            <dl class="facts">
                <div class="f"><dt>Fournisseur</dt><dd>{{ $reception->supplier?->name }}</dd></div>
                <div class="f"><dt>Reçue le</dt><dd>{{ $reception->received_on?->format('d/m/Y') }}</dd></div>
                <div class="f"><dt>Emplacement</dt><dd>{{ $reception->location?->name }}</dd></div>
                <div class="f"><dt>Bon de livraison</dt><dd class="mono">{{ $reception->delivery_note ?? '—' }}</dd></div>
                <div class="f"><dt>Commande</dt>
                    <dd>
                        @if ($reception->order)
                            <a href="{{ route('pharmacie.supply.orders.show', $reception->order) }}">{{ $reception->order->number }}</a>
                        @else
                            Hors commande
                        @endif
                    </dd>
                </div>
                <div class="f"><dt>Contrôlée par</dt><dd>{{ $reception->received_by_name ?? '—' }}</dd></div>
                <div class="f total"><dt>Total</dt><dd>{{ $money($reception->total) }}</dd></div>
            </dl>
        </x-pharmacie::card>

        <x-pharmacie::card title="Contrôle">
            @if ($reception->anomalies)
                <p><strong>Anomalies :</strong> {{ $reception->anomalies }}</p>
            @else
                <p class="muted">Aucune anomalie signalée à la réception.</p>
            @endif
            @if ($reception->notes)
                <p class="muted">{{ $reception->notes }}</p>
            @endif
            <p class="muted">
                Une réception ne se réécrit pas. Une erreur constatée plus tard se corrige
                par un ajustement de stock motivé, qui reste lisible à côté d'elle.
            </p>
        </x-pharmacie::card>
    </div>

    <x-pharmacie::card title="Ce qui est entré" hint="{{ $reception->lines->count() }} ligne(s)" flush>
        <div class="tw">
            <table class="stack wide">
                <thead><tr><th>Produit</th><th>Lot</th><th>Péremption</th><th class="num">Quantité</th><th class="num">Prix d'achat</th><th class="num">Montant</th></tr></thead>
                <tbody>
                @foreach ($reception->lines as $line)
                    <tr>
                        <td data-l="Produit" class="strong">{{ $line->product?->label() }}</td>
                        <td data-l="Lot" class="mono">
                            <a href="{{ route('pharmacie.stock.batches.show', $line->batch) }}">{{ $line->batch?->number }}</a>
                        </td>
                        <td data-l="Péremption">{{ $line->batch?->expires_on?->format('d/m/Y') ?? '—' }}</td>
                        <td data-l="Quantité" class="num strong">{{ $line->quantity }}</td>
                        <td data-l="Prix" class="num">{{ $money($line->unit_price) }}</td>
                        <td data-l="Montant" class="num">{{ $money($line->amount) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </x-pharmacie::card>
@endsection
