@extends('pharmacie::layout')

@section('title', 'Commandes')

@section('content')
    <x-pharmacie::page title="Commandes"
        sub="Ce qu'on a demandé, ce qui est arrivé, ce qui manque encore." />

    @if ($suggestions !== [])
        <x-pharmacie::card title="À commander" hint="Produits au seuil ou en rupture">
            <div class="tw">
                <table class="stack">
                    <thead><tr><th>Produit</th><th class="num">En stock</th><th class="num">Seuil</th><th class="num">Quantité suggérée</th></tr></thead>
                    <tbody>
                    @foreach ($suggestions as $row)
                        <tr>
                            <td data-l="Produit" class="strong">{{ $row['product']->label() }}</td>
                            <td data-l="En stock" class="num">{{ $row['available'] }}</td>
                            <td data-l="Seuil" class="num">{{ $row['product']->min_threshold }}</td>
                            <td data-l="Suggérée" class="num strong">{{ $row['suggested'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <p class="muted">La suggestion vise le seuil maximum, ou le double du seuil minimum s'il n'est pas fixé.</p>
        </x-pharmacie::card>
    @endif

    @can('pharmacie.stock.receive')
        <x-pharmacie::card title="Nouvelle commande" hint="Trois lignes à la fois ; on complète ensuite">
            @if ($suppliers->isEmpty() || $products->isEmpty())
                <p class="muted" style="margin:0">Il faut au moins un fournisseur et un produit actif pour commander.</p>
            @else
                <form method="post" action="{{ route('pharmacie.supply.orders.store') }}">
                    @csrf
                    <div class="row">
                        <label>Fournisseur
                            <select name="supplier_id" required>
                                @foreach ($suppliers as $supplier)
                                    <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>Livraison attendue <input type="date" name="expected_on" value="{{ old('expected_on') }}"></label>
                        <label>Observations <input name="notes" value="{{ old('notes') }}"></label>
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
                            <label>Quantité <input name="lines[{{ $i }}][quantity]" inputmode="numeric" @if ($i === 0) required @endif></label>
                            <label>Prix unitaire <input name="lines[{{ $i }}][unit_price]" class="money" inputmode="numeric" placeholder="0"></label>
                        </div>
                    @endfor

                    <div class="actions">
                        <button type="submit"><x-pharmacie::icon name="plus" /> Créer la commande</button>
                    </div>
                </form>
            @endif
        </x-pharmacie::card>
    @endcan

    <x-pharmacie::card flush>
        <div class="bd">
            <form method="get" action="{{ route('pharmacie.supply.orders.index') }}" class="inline">
                <label>Statut
                    <select name="statut">
                        <option value="">Tous</option>
                        @foreach ($statuses as $key => $label)
                            <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <button type="submit" class="ghost sm">Filtrer</button>
            </form>
        </div>

        @if ($orders->isEmpty())
            <div class="bd">
                <x-pharmacie::empty title="Aucune commande" icon="reception">
                    Une commande part d'un besoin : un produit sous son seuil, ou une rupture.
                </x-pharmacie::empty>
            </div>
        @else
            <div class="tw">
                <table class="stack wide">
                    <thead><tr><th>N°</th><th>Fournisseur</th><th>Attendue</th><th>Statut</th><th class="num">Reste à recevoir</th><th class="num">Montant</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($orders as $order)
                        <tr>
                            <td data-l="N°" class="mono strong">{{ $order->number }}</td>
                            <td data-l="Fournisseur">{{ $order->supplier?->name }}</td>
                            <td data-l="Attendue">{{ $order->expected_on?->format('d/m/Y') ?? '—' }}</td>
                            <td data-l="Statut"><span class="badge {{ $order->statusTone() }}">{{ $order->statusLabel() }}</span></td>
                            <td data-l="Reste" class="num">{{ $order->outstanding() }}</td>
                            <td data-l="Montant" class="num">{{ $money($order->total) }}</td>
                            <td data-l="" class="acts"><a class="btn ghost sm" href="{{ route('pharmacie.supply.orders.show', $order) }}">Ouvrir</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            @if ($orders->hasPages())
                <div class="bd pager">
                    @if ($orders->previousPageUrl()) <a class="btn ghost sm" href="{{ $orders->previousPageUrl() }}">← Précédentes</a> @endif
                    <span class="muted">Page {{ $orders->currentPage() }} sur {{ $orders->lastPage() }}</span>
                    @if ($orders->nextPageUrl()) <a class="btn ghost sm" href="{{ $orders->nextPageUrl() }}">Suivantes →</a> @endif
                </div>
            @endif
        @endif
    </x-pharmacie::card>
@endsection
