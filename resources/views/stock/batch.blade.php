@extends('pharmacie::layout')

@section('title', 'Lot '.$batch->number)

@section('content')
    <a class="back" href="{{ route('pharmacie.stock.products.show', $batch->product) }}">
        <x-pharmacie::icon name="retour" /> Retour au produit
    </a>

    <x-pharmacie::page :title="'Lot '.$batch->number" :sub="$batch->product?->label()">
        <x-slot:actions>
            <span class="badge {{ $batch->statusTone() }}">{{ $batch->statusLabel() }}</span>
        </x-slot:actions>
    </x-pharmacie::page>

    @if ($batch->block_reason)
        <x-pharmacie::card title="Lot bloqué">
            <p class="muted" style="margin:0">{{ $batch->block_reason }}</p>
        </x-pharmacie::card>
    @endif

    <div class="cols">
        <x-pharmacie::card title="Le lot">
            <dl class="facts">
                <div class="f"><dt>Fournisseur</dt><dd>{{ $batch->supplier_name ?? '—' }}</dd></div>
                <div class="f"><dt>Reçu le</dt><dd>{{ $batch->received_on?->format('d/m/Y') ?? '—' }}</dd></div>
                <div class="f"><dt>Fabriqué le</dt><dd>{{ $batch->manufactured_on?->format('d/m/Y') ?? '—' }}</dd></div>
                <div class="f"><dt>Périme le</dt><dd>{{ $batch->expires_on?->format('d/m/Y') ?? '—' }}</dd></div>
                <div class="f"><dt>Prix d'achat</dt><dd>{{ $batch->purchase_price === null ? '—' : $money($batch->purchase_price) }}</dd></div>
                <div class="f"><dt>Prix de vente</dt><dd>{{ $batch->sale_price === null ? '—' : $money($batch->sale_price) }}</dd></div>
                <div class="f total"><dt>Reste en stock</dt><dd>{{ $batch->onHand() }} {{ $batch->product?->unit }}</dd></div>
            </dl>
        </x-pharmacie::card>

        <x-pharmacie::card title="Où il se trouve">
            @if ($batch->stocks->isEmpty())
                <p class="muted" style="margin:0">Ce lot n'a plus d'unité en stock. Son histoire reste ci-dessous.</p>
            @else
                <dl class="facts">
                    @foreach ($batch->stocks as $stock)
                        <div class="f">
                            <dt>{{ $stock->location?->name }}</dt>
                            <dd>{{ $stock->quantity }} @if ($stock->reserved > 0) <span class="muted">(dont {{ $stock->reserved }} réservé)</span> @endif</dd>
                        </div>
                    @endforeach
                </dl>
            @endif

            @can('pharmacie.stock.adjust')
                <form method="post" action="{{ route('pharmacie.stock.batches.toggle', $batch) }}" class="inline" style="margin-top:1rem">
                    @csrf
                    @if ($batch->status !== 'blocked')
                        <input name="reason" placeholder="Motif du blocage" required>
                    @endif
                    <button type="submit" class="{{ $batch->status === 'blocked' ? 'ghost sm' : 'danger sm' }}">
                        {{ $batch->status === 'blocked' ? 'Débloquer le lot' : 'Bloquer le lot' }}
                    </button>
                </form>
            @endcan
        </x-pharmacie::card>
    </div>

    @can('pharmacie.stock.adjust')
        <x-pharmacie::card title="Ajuster le stock de ce lot" hint="Toujours avec un motif, toujours au grand livre">
            <form method="post" action="{{ route('pharmacie.stock.adjust') }}">
                @csrf
                <input type="hidden" name="batch_id" value="{{ $batch->id }}">
                <div class="row">
                    <label>Emplacement
                        <select name="location_id" required>
                            @foreach ($batch->stocks as $stock)
                                <option value="{{ $stock->location_id }}">{{ $stock->location?->name }} ({{ $stock->quantity }})</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Sens
                        <select name="direction" required>
                            <option value="sortie">Sortie (correction en moins)</option>
                            <option value="entree">Entrée (correction en plus)</option>
                        </select>
                    </label>
                    <label>Quantité <input name="quantity" inputmode="numeric" required></label>
                    <label>Motif <input name="reason" placeholder="ex. Écart constaté au comptage" required></label>
                </div>
                <div class="actions">
                    <button type="submit" class="ghost"><x-pharmacie::icon name="check" /> Enregistrer l'ajustement</button>
                </div>
            </form>
        </x-pharmacie::card>
    @endcan

    <x-pharmacie::card title="Histoire du lot" hint="{{ $movements->count() }} mouvement(s)" flush>
        @if ($movements->isEmpty())
            <div class="bd">
                <p class="muted" style="margin:0">Aucun mouvement enregistré.</p>
            </div>
        @else
            <div class="tw">
                <table class="stack wide">
                    <thead>
                    <tr><th>Date</th><th>Nature</th><th>Emplacement</th><th class="num">Quantité</th><th class="num">Après</th><th>Motif</th><th>Par</th></tr>
                    </thead>
                    <tbody>
                    @foreach ($movements as $movement)
                        <tr>
                            <td data-l="Date">{{ $movement->created_at?->format('d/m/Y H:i') }}</td>
                            <td data-l="Nature">
                                <span class="badge {{ $movement->isIncoming() ? 'ok' : 'muted' }}">{{ $movement->kindLabel() }}</span>
                                @if ($movement->document_number) <span class="sub mono">{{ $movement->document_number }}</span> @endif
                            </td>
                            <td data-l="Emplacement">{{ $movement->location?->name }}</td>
                            <td data-l="Quantité" class="num strong">{{ $movement->quantity > 0 ? '+' : '' }}{{ $movement->quantity }}</td>
                            <td data-l="Après" class="num">{{ $movement->quantity_after }}</td>
                            <td data-l="Motif">{{ $movement->reason ?? '—' }}</td>
                            <td data-l="Par">{{ $movement->actor_name ?? '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-pharmacie::card>
@endsection
