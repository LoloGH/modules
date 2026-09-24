@extends('pharmacie::layout')

@section('title', 'Inventaires')

@section('content')
    <x-pharmacie::page title="Inventaires"
        sub="Comparer ce que le système croyait avoir à ce qu'on a compté. L'écart se justifie, il ne s'efface pas." />

    @can('pharmacie.inventory.count')
        <x-pharmacie::card title="Ouvrir un inventaire" hint="Le théorique est figé à l'ouverture">
            @if ($locations->isEmpty())
                <p class="muted" style="margin:0">Aucun emplacement : rien à compter.</p>
            @else
                <form method="post" action="{{ route('pharmacie.inventory.open') }}">
                    @csrf
                    <div class="row">
                        <label>Emplacement
                            <select name="location_id" required>
                                @foreach ($locations as $location)
                                    <option value="{{ $location->id }}">{{ $location->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>Portée
                            <select name="scope" required>
                                @foreach ($scopes as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>Catégorie (si partiel)
                            <select name="category_id">
                                <option value="">—</option>
                                @foreach ($categories as $category)
                                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>Produit (si un seul)
                            <select name="product_id">
                                <option value="">—</option>
                                @foreach ($products as $product)
                                    <option value="{{ $product->id }}">{{ $product->label() }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>
                    <div class="actions">
                        <button type="submit"><x-pharmacie::icon name="inventaire" /> Ouvrir l'inventaire</button>
                    </div>
                </form>
            @endif
        </x-pharmacie::card>
    @endcan

    <x-pharmacie::card title="Inventaires" hint="{{ $inventories->total() }} au total" flush>
        @if ($inventories->isEmpty())
            <div class="bd">
                <x-pharmacie::empty title="Aucun inventaire" icon="inventaire">
                    Un inventaire régulier est ce qui garde un stock honnête.
                </x-pharmacie::empty>
            </div>
        @else
            <div class="tw">
                <table class="stack wide">
                    <thead><tr><th>N°</th><th>Emplacement</th><th>Portée</th><th class="num">Lignes</th><th class="num">Écarts</th><th>Compté par</th><th>État</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($inventories as $inventory)
                        <tr>
                            <td data-l="N°" class="mono strong">{{ $inventory->number }}</td>
                            <td data-l="Emplacement">{{ $inventory->location?->name }}</td>
                            <td data-l="Portée">{{ $inventory->scopeLabel() }}</td>
                            <td data-l="Lignes" class="num">{{ $inventory->lines->count() }}</td>
                            <td data-l="Écarts" class="num">{{ $inventory->gapCount() ?: '—' }}</td>
                            <td data-l="Compté par">{{ $inventory->counted_by_name ?? '—' }}</td>
                            <td data-l="État"><span class="badge {{ $inventory->statusTone() }}">{{ $inventory->statusLabel() }}</span></td>
                            <td data-l="" class="acts"><a class="btn ghost sm" href="{{ route('pharmacie.inventory.show', $inventory) }}">Ouvrir</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-pharmacie::card>
@endsection
