@extends('pharmacie::layout')

@section('title', 'Produits')

@section('content')
    <x-pharmacie::page title="Produits"
        sub="Le catalogue de la pharmacie : médicaments, consommables, dispositifs et articles d'hygiène." />

    <div class="kpis">
        <x-pharmacie::kpi label="Produits actifs" icon="produit" tone="green" :value="(string) $stats['total']" />
        <x-pharmacie::kpi label="Sous surveillance" icon="verrou" tone="amber" :value="(string) $stats['controlled']" foot="Contrôle renforcé" />
        <x-pharmacie::kpi label="Sans prix de vente" icon="alert" tone="red" :value="(string) $stats['priceless']" foot="À fixer avant toute vente" />
    </div>

    @can('pharmacie.products.manage')
        <x-pharmacie::card title="Référencer un produit" hint="Nom, dosage et forme : ce qui évite de délivrer le mauvais">
            <form method="post" action="{{ route('pharmacie.catalog.products.store') }}">
                @csrf
                <div class="row">
                    <label>Code interne <input name="code" value="{{ old('code') }}" placeholder="AMOX500" required></label>
                    <label>Nom <input name="name" value="{{ old('name') }}" placeholder="Amoxicilline" required></label>
                    <label>DCI <input name="dci" value="{{ old('dci') }}" placeholder="Amoxicilline"></label>
                </div>
                <div class="row">
                    <label>Nature
                        <select name="kind" required>
                            @foreach ($kinds as $key => $label)
                                <option value="{{ $key }}" @selected(old('kind', 'medicine') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Catégorie
                        <select name="category_id">
                            <option value="">Aucune</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}" @selected((string) old('category_id') === (string) $category->id)>{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Forme <input name="form" value="{{ old('form') }}" placeholder="Gélule"></label>
                    <label>Dosage <input name="dosage" value="{{ old('dosage') }}" placeholder="500 mg"></label>
                </div>
                <div class="row">
                    <label>Voie <input name="route" value="{{ old('route') }}" placeholder="Orale"></label>
                    <label>Unité <input name="unit" value="{{ old('unit', 'unité') }}" required></label>
                    <label>Conditionnement <input name="packaging" value="{{ old('packaging') }}" placeholder="Boîte de 12"></label>
                    <label>Code-barres <input name="barcode" value="{{ old('barcode') }}"></label>
                </div>
                <div class="row">
                    <label>Seuil minimum <input name="min_threshold" inputmode="numeric" value="{{ old('min_threshold', 0) }}" required></label>
                    <label>Seuil maximum <input name="max_threshold" inputmode="numeric" value="{{ old('max_threshold') }}"></label>
                    @can('pharmacie.prices.manage')
                        <label>Prix de vente
                            <input name="sale_price" class="money" inputmode="numeric" value="{{ old('sale_price') }}" placeholder="0">
                            <span class="help">En FCFA, sans décimale.</span>
                        </label>
                    @endcan
                    <label>Conservation <input name="storage_conditions" value="{{ old('storage_conditions') }}" placeholder="Entre 15 et 25 °C"></label>
                </div>
                <div class="checks">
                    <label><input type="checkbox" name="is_generic" value="1" @checked(old('is_generic'))> Générique</label>
                    <label><input type="checkbox" name="is_controlled" value="1" @checked(old('is_controlled'))> Contrôle renforcé</label>
                </div>
                <div class="actions">
                    <button type="submit"><x-pharmacie::icon name="plus" /> Référencer</button>
                </div>
            </form>
        </x-pharmacie::card>
    @endcan

    <x-pharmacie::card flush>
        <div class="bd">
            <form method="get" action="{{ route('pharmacie.catalog.products.index') }}">
                <div class="row">
                    <label>Recherche
                        <input name="q" value="{{ $search }}" placeholder="Nom, DCI, marque, code, code-barres">
                    </label>
                    <label>Nature
                        <select name="nature">
                            <option value="">Toutes</option>
                            @foreach ($kinds as $key => $label)
                                <option value="{{ $key }}" @selected($kind === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Catégorie
                        <select name="categorie">
                            <option value="">Toutes</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}" @selected($categoryId === $category->id)>{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>État
                        <select name="statut">
                            <option value="actifs" @selected($status === 'actifs')>Actifs</option>
                            <option value="inactifs" @selected($status === 'inactifs')>Désactivés</option>
                        </select>
                    </label>
                </div>
                <div class="actions">
                    <button type="submit"><x-pharmacie::icon name="check" /> Filtrer</button>
                    <a class="btn ghost" href="{{ route('pharmacie.catalog.products.index') }}">Tout voir</a>
                </div>
            </form>
        </div>

        @if ($products->isEmpty())
            <div class="bd">
                <x-pharmacie::empty title="Aucun produit" icon="produit">
                    Référencez un premier produit : sans catalogue, rien ne peut être reçu ni délivré.
                </x-pharmacie::empty>
            </div>
        @else
            <div class="tw">
                <table class="stack wide">
                    <thead>
                    <tr><th>Produit</th><th>Nature</th><th>Catégorie</th><th class="num">Seuil</th><th class="num">Prix</th><th>État</th><th></th></tr>
                    </thead>
                    <tbody>
                    @foreach ($products as $product)
                        <tr>
                            <td data-l="Produit" class="strong">
                                {{ $product->label() }}
                                <span class="sub mono">{{ $product->code }}@if ($product->dci) · {{ $product->dci }} @endif</span>
                                @if ($product->is_controlled) <span class="badge warn">Contrôle renforcé</span> @endif
                            </td>
                            <td data-l="Nature">{{ $product->kindLabel() }}</td>
                            <td data-l="Catégorie">{{ $product->category?->name ?? '-' }}</td>
                            <td data-l="Seuil" class="num">{{ $product->min_threshold }}</td>
                            <td data-l="Prix" class="num">{{ $product->sale_price === null ? '-' : $money($product->sale_price) }}</td>
                            <td data-l="État">
                                <span class="badge {{ $product->is_active ? 'ok' : 'off' }}">{{ $product->is_active ? 'Actif' : 'Désactivé' }}</span>
                            </td>
                            <td data-l="" class="acts">
                                <a class="btn ghost sm" href="{{ route('pharmacie.catalog.products.show', $product) }}">Fiche</a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            @if ($products->hasPages())
                <div class="bd pager">
                    @if ($products->previousPageUrl()) <a class="btn ghost sm" href="{{ $products->previousPageUrl() }}">Précédents</a> @endif
                    <span class="muted">Page {{ $products->currentPage() }} sur {{ $products->lastPage() }}</span>
                    @if ($products->nextPageUrl()) <a class="btn ghost sm" href="{{ $products->nextPageUrl() }}">Suivants</a> @endif
                </div>
            @endif
        @endif
    </x-pharmacie::card>
@endsection
