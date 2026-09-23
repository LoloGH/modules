@extends('pharmacie::layout')

@section('title', $product->name)

@section('content')
    <a class="back" href="{{ route('pharmacie.catalog.products.index') }}">
        <x-pharmacie::icon name="retour" /> Retour au catalogue
    </a>

    <x-pharmacie::page :title="$product->label()" :sub="$product->code.' · '.$product->kindLabel()">
        <x-slot:actions>
            @if ($product->is_controlled) <span class="badge warn">Contrôle renforcé</span> @endif
            <span class="badge {{ $product->is_active ? 'ok' : 'off' }}">{{ $product->is_active ? 'Actif' : 'Désactivé' }}</span>
        </x-slot:actions>
    </x-pharmacie::page>

    <div class="cols">
        <x-pharmacie::card title="Identification">
            <dl class="facts">
                <div class="f"><dt>DCI</dt><dd>{{ $product->dci ?? '—' }}</dd></div>
                <div class="f"><dt>Nom commercial</dt><dd>{{ $product->brand_name ?? '—' }}</dd></div>
                <div class="f"><dt>Laboratoire</dt><dd>{{ $product->laboratory ?? '—' }}</dd></div>
                <div class="f"><dt>Classe thérapeutique</dt><dd>{{ $product->therapeutic_class ?? '—' }}</dd></div>
                <div class="f"><dt>Catégorie</dt><dd>{{ $product->category?->name ?? '—' }}</dd></div>
                <div class="f"><dt>Code-barres</dt><dd class="mono">{{ $product->barcode ?? '—' }}</dd></div>
                <div class="f"><dt>Générique</dt><dd>{{ $product->is_generic ? 'Oui' : 'Non' }}</dd></div>
            </dl>
        </x-pharmacie::card>

        <x-pharmacie::card title="Présentation et gestion">
            <dl class="facts">
                <div class="f"><dt>Forme</dt><dd>{{ $product->form ?? '—' }}</dd></div>
                <div class="f"><dt>Dosage</dt><dd>{{ $product->dosage ?? '—' }}</dd></div>
                <div class="f"><dt>Voie d'administration</dt><dd>{{ $product->route ?? '—' }}</dd></div>
                <div class="f"><dt>Unité</dt><dd>{{ $product->unit }}</dd></div>
                <div class="f"><dt>Conditionnement</dt><dd>{{ $product->packaging ?? '—' }}</dd></div>
                <div class="f"><dt>Seuils</dt><dd>{{ $product->min_threshold }}@if ($product->max_threshold) – {{ $product->max_threshold }} @endif</dd></div>
                <div class="f"><dt>Conservation</dt><dd>{{ $product->storage_conditions ?? '—' }}</dd></div>
                <div class="f total"><dt>Prix de vente</dt><dd>{{ $product->sale_price === null ? 'À fixer' : $money($product->sale_price) }}</dd></div>
            </dl>
        </x-pharmacie::card>
    </div>

    @if ($product->description)
        <x-pharmacie::card title="Description">{{ $product->description }}</x-pharmacie::card>
    @endif

    @can('pharmacie.products.manage')
        <x-pharmacie::card title="Modifier le produit">
            <form method="post" action="{{ route('pharmacie.catalog.products.update', $product) }}">
                @csrf
                <div class="row">
                    <label>Code interne <input name="code" value="{{ old('code', $product->code) }}" required></label>
                    <label>Nom <input name="name" value="{{ old('name', $product->name) }}" required></label>
                    <label>DCI <input name="dci" value="{{ old('dci', $product->dci) }}"></label>
                </div>
                <div class="row">
                    <label>Nom commercial <input name="brand_name" value="{{ old('brand_name', $product->brand_name) }}"></label>
                    <label>Laboratoire <input name="laboratory" value="{{ old('laboratory', $product->laboratory) }}"></label>
                    <label>Classe thérapeutique <input name="therapeutic_class" value="{{ old('therapeutic_class', $product->therapeutic_class) }}"></label>
                </div>
                <div class="row">
                    <label>Nature
                        <select name="kind" required>
                            @foreach ($kinds as $key => $label)
                                <option value="{{ $key }}" @selected(old('kind', $product->kind) === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Catégorie
                        <select name="category_id">
                            <option value="">— Aucune</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}" @selected((string) old('category_id', $product->category_id) === (string) $category->id)>{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Forme <input name="form" value="{{ old('form', $product->form) }}"></label>
                    <label>Dosage <input name="dosage" value="{{ old('dosage', $product->dosage) }}"></label>
                </div>
                <div class="row">
                    <label>Voie <input name="route" value="{{ old('route', $product->route) }}"></label>
                    <label>Unité <input name="unit" value="{{ old('unit', $product->unit) }}" required></label>
                    <label>Conditionnement <input name="packaging" value="{{ old('packaging', $product->packaging) }}"></label>
                    <label>Code-barres <input name="barcode" value="{{ old('barcode', $product->barcode) }}"></label>
                </div>
                <div class="row">
                    <label>Seuil minimum <input name="min_threshold" inputmode="numeric" value="{{ old('min_threshold', $product->min_threshold) }}" required></label>
                    <label>Seuil maximum <input name="max_threshold" inputmode="numeric" value="{{ old('max_threshold', $product->max_threshold) }}"></label>
                    @can('pharmacie.prices.manage')
                        <label>Prix de vente <input name="sale_price" class="money" inputmode="numeric" value="{{ old('sale_price', $product->sale_price) }}"></label>
                    @endcan
                    <label>Conservation <input name="storage_conditions" value="{{ old('storage_conditions', $product->storage_conditions) }}"></label>
                </div>
                <label>Description <input name="description" value="{{ old('description', $product->description) }}"></label>
                <div class="checks">
                    <label><input type="checkbox" name="is_generic" value="1" @checked(old('is_generic', $product->is_generic))> Générique</label>
                    <label><input type="checkbox" name="is_controlled" value="1" @checked(old('is_controlled', $product->is_controlled))> Contrôle renforcé</label>
                </div>
                <div class="actions">
                    <button type="submit"><x-pharmacie::icon name="check" /> Enregistrer</button>
                </div>
            </form>

            <form method="post" action="{{ route('pharmacie.catalog.products.toggle', $product) }}" class="inline" style="margin-top:1rem">
                @csrf
                <button type="submit" class="ghost sm">{{ $product->is_active ? 'Désactiver le produit' : 'Réactiver le produit' }}</button>
                <span class="muted">Un produit désactivé ne se propose plus, mais tout ce qu'il a porté reste lisible.</span>
            </form>
        </x-pharmacie::card>
    @endcan
@endsection
