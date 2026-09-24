@extends('pharmacie::layout')

@section('title', 'Registre des produits sous surveillance')

@section('content')
    <x-pharmacie::page title="Registre des produits sous surveillance"
        sub="Chaque entrée, chaque sortie, dans l'ordre, avec le solde après." />

    @if ($products->isEmpty())
        <x-pharmacie::card title="Aucun produit sous surveillance">
            <x-pharmacie::empty title="Rien à surveiller" icon="verrou">
                Aucun produit du catalogue n'est marqué « sous surveillance ».
                Le drapeau se pose sur la fiche du produit.
            </x-pharmacie::empty>
        </x-pharmacie::card>
    @else
        <x-pharmacie::card title="Choisir le produit">
            <form method="get" action="{{ route('pharmacie.vigilance.register') }}" class="row">
                <label>Produit
                    <select name="product_id" onchange="this.form.submit()">
                        @foreach ($products as $item)
                            <option value="{{ $item->id }}" @selected($product && $item->id === $product->id)>
                                {{ $item->label() }}
                            </option>
                        @endforeach
                    </select>
                </label>
                <div class="actions">
                    <button type="submit"><x-pharmacie::icon name="check" /> Afficher</button>
                </div>
            </form>
        </x-pharmacie::card>

        <div class="kpis">
            <x-pharmacie::kpi label="En stock" icon="lot" :value="(string) $onHand"
                :foot="$product?->unit ?? 'unité'" />
            <x-pharmacie::kpi label="Écritures" icon="document" :value="(string) $movements->count()"
                foot="Depuis l'ouverture du registre" />
        </div>

        <x-pharmacie::card :title="'Registre — '.($product?->label() ?? '')"
            hint="Du plus récent au plus ancien" flush>
            @if ($movements->isEmpty())
                <div class="bd">
                    <x-pharmacie::empty title="Aucun mouvement" icon="vide">
                        Ce produit n'est encore jamais entré ni sorti.
                    </x-pharmacie::empty>
                </div>
            @else
                <div class="tw">
                    <table class="stack wide">
                        <thead><tr>
                            <th>Date</th><th>Nature</th><th>Lot</th><th>Emplacement</th>
                            <th class="num">Entrée</th><th class="num">Sortie</th><th class="num">Solde</th>
                            <th>Pièce</th><th>Par</th>
                        </tr></thead>
                        <tbody>
                        @foreach ($movements as $movement)
                            <tr>
                                <td data-l="Date">{{ $movement->created_at?->format('d/m/Y H:i') }}</td>
                                <td data-l="Nature">{{ $kinds[$movement->kind] ?? $movement->kind }}</td>
                                <td data-l="Lot" class="mono">{{ $movement->batch?->number }}</td>
                                <td data-l="Emplacement">{{ $movement->location?->name }}</td>
                                <td data-l="Entrée" class="num">{{ $movement->quantity > 0 ? $movement->quantity : '—' }}</td>
                                <td data-l="Sortie" class="num">{{ $movement->quantity < 0 ? abs($movement->quantity) : '—' }}</td>
                                <td data-l="Solde" class="num strong">{{ $balances[$movement->id] ?? '—' }}</td>
                                <td data-l="Pièce" class="mono">
                                    {{ $movement->document_number ?? '—' }}
                                    @if ($movement->reason) <span class="sub">{{ $movement->reason }}</span> @endif
                                </td>
                                <td data-l="Par">{{ $movement->actor_name ?? '—' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-pharmacie::card>
    @endif
@endsection
