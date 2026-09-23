@extends('pharmacie::layout')

@section('title', $supplier->name)

@section('content')
    <a class="back" href="{{ route('pharmacie.supply.suppliers.index') }}">
        <x-pharmacie::icon name="retour" /> Retour aux fournisseurs
    </a>

    <x-pharmacie::page :title="$supplier->name" :sub="$supplier->code">
        <x-slot:actions>
            <span class="badge {{ $supplier->is_active ? 'ok' : 'off' }}">{{ $supplier->is_active ? 'Actif' : 'Désactivé' }}</span>
        </x-slot:actions>
    </x-pharmacie::page>

    <div class="cols">
        <x-pharmacie::card title="Coordonnées">
            <dl class="facts">
                <div class="f"><dt>Contact</dt><dd>{{ $supplier->contact_name ?? '—' }}</dd></div>
                <div class="f"><dt>Téléphone</dt><dd>{{ $supplier->phone ?? '—' }}</dd></div>
                <div class="f"><dt>E-mail</dt><dd>{{ $supplier->email ?? '—' }}</dd></div>
                <div class="f"><dt>Adresse</dt><dd>{{ $supplier->address ?? '—' }}</dd></div>
            </dl>
        </x-pharmacie::card>

        <x-pharmacie::card title="Conditions">
            <dl class="facts">
                <div class="f"><dt>Délai de paiement</dt><dd>{{ $supplier->payment_days === null ? '—' : $supplier->payment_days.' jour(s)' }}</dd></div>
                <div class="f"><dt>Délai de livraison</dt><dd>{{ $supplier->lead_time_days === null ? '—' : $supplier->lead_time_days.' jour(s)' }}</dd></div>
                <div class="f"><dt>Observations</dt><dd>{{ $supplier->notes ?? '—' }}</dd></div>
            </dl>

            @can('pharmacie.stock.receive')
                <form method="post" action="{{ route('pharmacie.supply.suppliers.toggle', $supplier) }}" class="inline" style="margin-top:1rem">
                    @csrf
                    <button type="submit" class="ghost sm">{{ $supplier->is_active ? 'Désactiver' : 'Réactiver' }}</button>
                </form>
            @endcan
        </x-pharmacie::card>
    </div>

    <x-pharmacie::card title="Commandes" hint="{{ $orders->count() }} commande(s)" flush>
        @if ($orders->isEmpty())
            <div class="bd"><p class="muted" style="margin:0">Aucune commande passée à ce fournisseur.</p></div>
        @else
            <div class="tw">
                <table class="stack">
                    <thead><tr><th>N°</th><th>Date</th><th>Statut</th><th class="num">Montant</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($orders as $order)
                        <tr>
                            <td data-l="N°" class="mono">{{ $order->number }}</td>
                            <td data-l="Date">{{ $order->ordered_on?->format('d/m/Y') }}</td>
                            <td data-l="Statut"><span class="badge {{ $order->statusTone() }}">{{ $order->statusLabel() }}</span></td>
                            <td data-l="Montant" class="num">{{ $money($order->total) }}</td>
                            <td data-l="" class="acts"><a class="btn ghost sm" href="{{ route('pharmacie.supply.orders.show', $order) }}">Voir</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-pharmacie::card>

    <x-pharmacie::card title="Réceptions" hint="{{ $receptions->count() }} réception(s)" flush>
        @if ($receptions->isEmpty())
            <div class="bd"><p class="muted" style="margin:0">Rien n'est encore venu de chez lui.</p></div>
        @else
            <div class="tw">
                <table class="stack">
                    <thead><tr><th>N°</th><th>Date</th><th class="num">Lignes</th><th class="num">Montant</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($receptions as $reception)
                        <tr>
                            <td data-l="N°" class="mono">{{ $reception->number }}</td>
                            <td data-l="Date">{{ $reception->received_on?->format('d/m/Y') }}</td>
                            <td data-l="Lignes" class="num">{{ $reception->lines->count() }}</td>
                            <td data-l="Montant" class="num">{{ $money($reception->total) }}</td>
                            <td data-l="" class="acts"><a class="btn ghost sm" href="{{ route('pharmacie.supply.receptions.show', $reception) }}">Bon</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-pharmacie::card>
@endsection
