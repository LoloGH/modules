@extends('pharmacie::layout')

@section('title', 'Catégories')

@section('content')
    <x-pharmacie::page title="Catégories"
        sub="Elles rangent le catalogue et filtrent les écrans. Une catégorie se désactive, elle ne se supprime pas." />

    @can('pharmacie.products.manage')
        <x-pharmacie::card title="Nouvelle catégorie">
            <form method="post" action="{{ route('pharmacie.catalog.categories.store') }}">
                @csrf
                <div class="row">
                    <label>Code <input name="code" value="{{ old('code') }}" placeholder="ANTIBIO" required></label>
                    <label>Nom <input name="name" value="{{ old('name') }}" placeholder="Antibiotiques" required></label>
                </div>
                <div class="actions">
                    <button type="submit"><x-pharmacie::icon name="plus" /> Créer la catégorie</button>
                </div>
            </form>
        </x-pharmacie::card>
    @endcan

    <x-pharmacie::card title="Catégories de la pharmacie" hint="{{ $categories->count() }} catégorie(s)" flush>
        @if ($categories->isEmpty())
            <div class="bd">
                <x-pharmacie::empty title="Aucune catégorie" icon="inventaire">
                    Créez-en une pour ranger le catalogue.
                </x-pharmacie::empty>
            </div>
        @else
            <div class="tw">
                <table class="stack">
                    <thead><tr><th>Nom</th><th>Code</th><th class="num">Produits</th><th>État</th>@can('pharmacie.products.manage')<th></th>@endcan</tr></thead>
                    <tbody>
                    @foreach ($categories as $category)
                        <tr>
                            <td data-l="Nom" class="strong">{{ $category->name }}</td>
                            <td data-l="Code" class="mono">{{ $category->code }}</td>
                            <td data-l="Produits" class="num">{{ $category->products_count }}</td>
                            <td data-l="État">
                                <span class="badge {{ $category->is_active ? 'ok' : 'off' }}">{{ $category->is_active ? 'Active' : 'Désactivée' }}</span>
                            </td>
                            @can('pharmacie.products.manage')
                                <td data-l="" class="acts">
                                    <form method="post" action="{{ route('pharmacie.catalog.categories.toggle', $category) }}">@csrf
                                        <button type="submit" class="ghost sm">{{ $category->is_active ? 'Désactiver' : 'Activer' }}</button>
                                    </form>
                                </td>
                            @endcan
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-pharmacie::card>
@endsection
