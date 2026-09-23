@extends('pharmacie::layout')

@section('title', 'Stock')

@section('content')
    <x-pharmacie::page title="Stock"
        sub="Ce qu'il y a, ce qui est promis, ce qu'on peut servir — produit par produit." />

    <div class="kpis">
        <x-pharmacie::kpi label="Valeur du stock" icon="lot" tone="green" :value="$money($totals['value'])" foot="Au prix d'achat des lots" />
        <x-pharmacie::kpi label="Produits suivis" icon="produit" tone="blue" :value="(string) $totals['lines']" />
    </div>

    <x-pharmacie::card flush>
        <div class="bd">
            <form method="get" action="{{ route('pharmacie.stock.index') }}">
                <div class="row">
                    <label>Recherche <input name="q" value="{{ $search }}" placeholder="Nom, DCI, code"></label>
                    <label>Emplacement
                        <select name="emplacement">
                            <option value="">Tous</option>
                            @foreach ($locations as $location)
                                <option value="{{ $location->id }}" @selected($locationId === $location->id)>{{ $location->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Alerte
                        <select name="alerte">
                            <option value="">Toutes les situations</option>
                            <option value="rupture" @selected($alert === 'rupture')>En rupture</option>
                            <option value="seuil" @selected($alert === 'seuil')>Sous le seuil</option>
                            <option value="peremption" @selected($alert === 'peremption')>Péremption proche</option>
                        </select>
                    </label>
                </div>
                <div class="actions">
                    <button type="submit"><x-pharmacie::icon name="check" /> Filtrer</button>
                    <a class="btn ghost" href="{{ route('pharmacie.stock.index') }}">Tout voir</a>
                    <a class="btn ghost" href="{{ route('pharmacie.stock.expiring') }}"><x-pharmacie::icon name="alert" /> Péremptions</a>
                </div>
            </form>
        </div>

        @if ($rows === [])
            <div class="bd">
                <x-pharmacie::empty title="Rien à afficher" icon="lot">
                    Aucun produit ne correspond. Le stock se remplit par une réception.
                </x-pharmacie::empty>
            </div>
        @else
            <div class="tw">
                <table class="stack wide">
                    <thead>
                    <tr>
                        <th>Produit</th><th class="num">Physique</th><th class="num">Réservé</th>
                        <th class="num">Disponible</th><th class="num">Lots</th><th>Situation</th><th></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($rows as $row)
                        @php($product = $row['product'])
                        <tr>
                            <td data-l="Produit" class="strong">
                                {{ $product->label() }}
                                <span class="sub mono">{{ $product->code }}</span>
                            </td>
                            <td data-l="Physique" class="num">{{ $row['on_hand'] }}</td>
                            <td data-l="Réservé" class="num">{{ $row['reserved'] }}</td>
                            <td data-l="Disponible" class="num strong">{{ $row['available'] }}</td>
                            <td data-l="Lots" class="num">{{ $row['batches'] }}</td>
                            <td data-l="Situation">
                                @if ($row['available'] <= 0)
                                    <span class="badge danger">Rupture</span>
                                @elseif ($row['available'] <= $product->min_threshold)
                                    <span class="badge warn">Sous le seuil ({{ $product->min_threshold }})</span>
                                @else
                                    <span class="badge ok">Suffisant</span>
                                @endif
                                @if ($row['expiring'] > 0)
                                    <span class="badge warn">{{ $row['expiring'] }} à péremption proche</span>
                                @endif
                            </td>
                            <td data-l="" class="acts">
                                <a class="btn ghost sm" href="{{ route('pharmacie.stock.products.show', $product) }}">Lots</a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            @if ($products->hasPages())
                <div class="bd pager">
                    @if ($products->previousPageUrl()) <a class="btn ghost sm" href="{{ $products->previousPageUrl() }}">← Précédents</a> @endif
                    <span class="muted">Page {{ $products->currentPage() }} sur {{ $products->lastPage() }}</span>
                    @if ($products->nextPageUrl()) <a class="btn ghost sm" href="{{ $products->nextPageUrl() }}">Suivants →</a> @endif
                </div>
            @endif
        @endif
    </x-pharmacie::card>
@endsection
