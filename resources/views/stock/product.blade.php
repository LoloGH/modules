@extends('pharmacie::layout')

@section('title', 'Stock — '.$product->name)

@section('content')
    <a class="back" href="{{ route('pharmacie.stock.index') }}">
        <x-pharmacie::icon name="retour" /> Retour au stock
    </a>

    <x-pharmacie::page :title="$product->label()" :sub="'Lots et emplacements · '.$product->code">
        <x-slot:actions>
            @can('pharmacie.products.view')
                <a class="btn ghost sm" href="{{ route('pharmacie.catalog.products.show', $product) }}">Fiche produit</a>
            @endcan
        </x-slot:actions>
    </x-pharmacie::page>

    <x-pharmacie::card title="Lots en stock" hint="{{ $stocks->count() }} ligne(s)" flush>
        @if ($stocks->isEmpty())
            <div class="bd">
                <x-pharmacie::empty title="Aucun lot" icon="lot">
                    Ce produit n'a encore rien reçu. Le stock se remplit par une réception.
                </x-pharmacie::empty>
            </div>
        @else
            <div class="tw">
                <table class="stack wide">
                    <thead>
                    <tr><th>Lot</th><th>Péremption</th><th>Emplacement</th><th class="num">Physique</th><th class="num">Réservé</th><th class="num">Disponible</th><th>État</th><th></th></tr>
                    </thead>
                    <tbody>
                    @foreach ($stocks as $stock)
                        @php($batch = $stock->batch)
                        <tr>
                            <td data-l="Lot" class="mono strong">{{ $batch?->number }}</td>
                            <td data-l="Péremption">
                                {{ $batch?->expires_on?->format('d/m/Y') ?? '—' }}
                                @if ($batch?->daysToExpiry() !== null)
                                    <span class="sub">
                                        @if ($batch->daysToExpiry() < 0)
                                            périmé depuis {{ abs($batch->daysToExpiry()) }} jour(s)
                                        @elseif ($batch->daysToExpiry() <= $warningDays)
                                            dans {{ $batch->daysToExpiry() }} jour(s)
                                        @endif
                                    </span>
                                @endif
                            </td>
                            <td data-l="Emplacement">{{ $stock->location?->name }}</td>
                            <td data-l="Physique" class="num">{{ $stock->quantity }}</td>
                            <td data-l="Réservé" class="num">{{ $stock->reserved }}</td>
                            <td data-l="Disponible" class="num strong">{{ $stock->available() }}</td>
                            <td data-l="État"><span class="badge {{ $batch?->statusTone() }}">{{ $batch?->statusLabel() }}</span></td>
                            <td data-l="" class="acts">
                                <a class="btn ghost sm" href="{{ route('pharmacie.stock.batches.show', $batch) }}">Histoire du lot</a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-pharmacie::card>
@endsection
