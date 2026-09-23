@extends('pharmacie::layout')

@section('title', 'Réceptions')

@section('content')
    <x-pharmacie::page title="Réceptions"
        sub="Contrôler ce qui arrive, relever les lots et les péremptions, puis faire entrer le stock." />

    @can('pharmacie.stock.receive')
        <x-pharmacie::card title="Enregistrer une réception" hint="Le lot et la péremption sont obligatoires">
            @if ($suppliers->isEmpty() || $locations->isEmpty() || $products->isEmpty())
                <p class="muted" style="margin:0">
                    Il faut un fournisseur, un emplacement et un produit actif avant de pouvoir recevoir.
                </p>
            @else
                <form method="post" action="{{ route('pharmacie.supply.receptions.store') }}">
                    @csrf
                    <div class="row">
                        <label>Fournisseur
                            <select name="supplier_id" required>
                                @foreach ($suppliers as $supplier)
                                    <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>Emplacement
                            <select name="location_id" required>
                                @foreach ($locations as $location)
                                    <option value="{{ $location->id }}">{{ $location->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>Commande
                            <select name="purchase_order_id">
                                <option value="">— Hors commande</option>
                                @foreach ($openOrders as $order)
                                    <option value="{{ $order->id }}">{{ $order->number }} · {{ $order->supplier?->name }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>
                    <div class="row">
                        <label>Reçu le <input type="date" name="received_on" value="{{ old('received_on', now()->toDateString()) }}"></label>
                        <label>Bon de livraison <input name="delivery_note" value="{{ old('delivery_note') }}"></label>
                        <label>Anomalies constatées <input name="anomalies" value="{{ old('anomalies') }}" placeholder="ex. 2 flacons cassés"></label>
                    </div>

                    @for ($i = 0; $i < 3; $i++)
                        <div class="row">
                            <label>Produit {{ $i + 1 }}
                                <select name="lines[{{ $i }}][product_id]" @if ($i === 0) required @endif>
                                    @if ($i > 0) <option value="">— Aucun</option> @endif
                                    @foreach ($products as $product)
                                        <option value="{{ $product->id }}">{{ $product->label() }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label>N° de lot <input name="lines[{{ $i }}][batch_number]" @if ($i === 0) required @endif></label>
                            <label>Péremption <input type="date" name="lines[{{ $i }}][expires_on]"></label>
                            <label>Quantité <input name="lines[{{ $i }}][quantity]" inputmode="numeric" @if ($i === 0) required @endif></label>
                            <label>Prix d'achat <input name="lines[{{ $i }}][unit_price]" class="money" inputmode="numeric" placeholder="0"></label>
                        </div>
                    @endfor

                    <div class="actions">
                        <button type="submit"><x-pharmacie::icon name="reception" /> Enregistrer la réception</button>
                    </div>
                </form>
            @endif
        </x-pharmacie::card>
    @endcan

    <x-pharmacie::card title="Réceptions enregistrées" hint="{{ $receptions->total() }} au total" flush>
        @if ($receptions->isEmpty())
            <div class="bd">
                <x-pharmacie::empty title="Aucune réception" icon="reception">
                    Le stock se remplit ici : chaque ligne fait naître un lot.
                </x-pharmacie::empty>
            </div>
        @else
            <div class="tw">
                <table class="stack wide">
                    <thead><tr><th>N°</th><th>Fournisseur</th><th>Emplacement</th><th>Date</th><th class="num">Lignes</th><th class="num">Montant</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($receptions as $reception)
                        <tr>
                            <td data-l="N°" class="mono strong">
                                {{ $reception->number }}
                                @if ($reception->anomalies) <span class="badge warn">Anomalie</span> @endif
                            </td>
                            <td data-l="Fournisseur">{{ $reception->supplier?->name }}</td>
                            <td data-l="Emplacement">{{ $reception->location?->name }}</td>
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
