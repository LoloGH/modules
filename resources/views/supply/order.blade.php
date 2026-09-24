@extends('pharmacie::layout')

@section('title', 'Commande '.$order->number)

@section('content')
    <a class="back" href="{{ route('pharmacie.supply.orders.index') }}">
        <x-pharmacie::icon name="retour" /> Retour aux commandes
    </a>

    <x-pharmacie::page :title="'Commande '.$order->number" :sub="$order->supplier?->name">
        <x-slot:actions>
            <span class="badge {{ $order->statusTone() }}">{{ $order->statusLabel() }}</span>
        </x-slot:actions>
    </x-pharmacie::page>

    @if ($order->cancellation_reason)
        <x-pharmacie::card title="Commande annulée">
            <p class="muted" style="margin:0">{{ $order->cancellation_reason }}</p>
        </x-pharmacie::card>
    @endif

    <x-pharmacie::card title="Lignes commandées" hint="{{ $order->items->count() }} ligne(s)" flush>
        <div class="tw">
            <table class="stack wide">
                <thead><tr><th>Produit</th><th class="num">Commandé</th><th class="num">Reçu</th><th class="num">Reste</th><th class="num">Prix unitaire</th><th class="num">Montant</th></tr></thead>
                <tbody>
                @foreach ($order->items as $item)
                    <tr>
                        <td data-l="Produit" class="strong">{{ $item->label }}</td>
                        <td data-l="Commandé" class="num">{{ $item->quantity }}</td>
                        <td data-l="Reçu" class="num">{{ $item->received_quantity }}</td>
                        <td data-l="Reste" class="num strong">{{ $item->outstanding() }}</td>
                        <td data-l="Prix" class="num">{{ $money($item->unit_price) }}</td>
                        <td data-l="Montant" class="num">{{ $money($item->amount) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="bd">
            <dl class="facts">
                <div class="f"><dt>Créée par</dt><dd>{{ $order->created_by_name ?? '-' }}</dd></div>
                <div class="f"><dt>Envoyée</dt><dd>{{ $order->sent_at?->format('d/m/Y H:i') ?? '-' }}</dd></div>
                <div class="f total"><dt>Total</dt><dd>{{ $money($order->total) }}</dd></div>
            </dl>
        </div>
    </x-pharmacie::card>

    @can('pharmacie.stock.receive')
        <x-pharmacie::card title="Suivi">
            @if ($order->isEditable())
                <form method="post" action="{{ route('pharmacie.supply.orders.send', $order) }}" class="inline">
                    @csrf
                    <button type="submit"><x-pharmacie::icon name="fleche" /> Envoyer au fournisseur</button>
                    <span class="muted">Une commande envoyée ne se réécrit plus : c'est un engagement.</span>
                </form>
            @endif

            @if ($order->status !== 'cancelled' && (int) $order->items->sum('received_quantity') === 0)
                <form method="post" action="{{ route('pharmacie.supply.orders.cancel', $order) }}" class="inline" style="margin-top:.75rem">
                    @csrf
                    <input name="reason" placeholder="Motif de l'annulation" required>
                    <button type="submit" class="danger sm">Annuler la commande</button>
                </form>
            @endif

            @if ($order->isOpen())
                <p class="muted">
                    Pour enregistrer une livraison, passez par
                    <a href="{{ route('pharmacie.supply.receptions.index') }}">Réceptions</a> : c'est là que les lots naissent.
                </p>
            @endif
        </x-pharmacie::card>
    @endcan

    @if ($order->receptions->isNotEmpty())
        <x-pharmacie::card title="Livraisons reçues" flush>
            <div class="tw">
                <table class="stack">
                    <thead><tr><th>N°</th><th>Date</th><th class="num">Montant</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($order->receptions as $reception)
                        <tr>
                            <td data-l="N°" class="mono">{{ $reception->number }}</td>
                            <td data-l="Date">{{ $reception->received_on?->format('d/m/Y') }}</td>
                            <td data-l="Montant" class="num">{{ $money($reception->total) }}</td>
                            <td data-l="" class="acts"><a class="btn ghost sm" href="{{ route('pharmacie.supply.receptions.show', $reception) }}">Bon</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </x-pharmacie::card>
    @endif
@endsection
