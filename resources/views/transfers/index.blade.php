@extends('pharmacie::layout')

@section('title', 'Transferts')

@section('content')
    <x-pharmacie::page title="Transferts"
        sub="D'un emplacement à un autre : demandé, validé, envoyé, reçu. Entre l'envoi et la réception, les unités sont en transit." />

    <div class="kpis">
        <x-pharmacie::kpi label="En transit" icon="reception" tone="amber" :value="(string) $inTransit"
                          foot="Parties, pas encore arrivées" />
    </div>

    @can('pharmacie.stock.adjust')
        <x-pharmacie::card title="Demander un transfert">
            @if ($locations->count() < 2)
                <p class="muted" style="margin:0">Il faut au moins deux emplacements pour transférer quelque chose.</p>
            @else
                <form method="post" action="{{ route('pharmacie.transfers.store') }}">
                    @csrf
                    <div class="row">
                        <label>De
                            <select name="from_location_id" required>
                                @foreach ($locations as $location)
                                    <option value="{{ $location->id }}">{{ $location->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>Vers
                            <select name="to_location_id" required>
                                @foreach ($locations as $location)
                                    <option value="{{ $location->id }}" @selected($loop->index === 1)>{{ $location->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>Motif <input name="reason" placeholder="ex. réassort du comptoir"></label>
                    </div>

                    @for ($i = 0; $i < 3; $i++)
                        <div class="row">
                            <label>Produit {{ $i + 1 }}
                                <select name="lines[{{ $i }}][product_id]">
                                    <option value="">— Aucun</option>
                                    @foreach ($products as $product)
                                        <option value="{{ $product->id }}">{{ $product->label() }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label>Quantité <input name="lines[{{ $i }}][quantity]" inputmode="numeric"></label>
                        </div>
                    @endfor

                    <div class="actions">
                        <button type="submit"><x-pharmacie::icon name="fleche" /> Demander</button>
                    </div>
                </form>
            @endif
        </x-pharmacie::card>
    @endcan

    <x-pharmacie::card flush>
        <div class="bd">
            <form method="get" action="{{ route('pharmacie.transfers.index') }}" class="inline">
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

        @if ($transfers->isEmpty())
            <div class="bd">
                <x-pharmacie::empty title="Aucun transfert" icon="reception">
                    Un transfert déplace du stock d'un emplacement vers un autre, sans jamais le perdre de vue.
                </x-pharmacie::empty>
            </div>
        @else
            <div class="tw">
                <table class="stack wide">
                    <thead><tr><th>N°</th><th>De</th><th>Vers</th><th class="num">Lignes</th><th>Demandé par</th><th>État</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($transfers as $transfer)
                        <tr>
                            <td data-l="N°" class="mono strong">{{ $transfer->number }}</td>
                            <td data-l="De">{{ $transfer->from?->name }}</td>
                            <td data-l="Vers">{{ $transfer->to?->name }}</td>
                            <td data-l="Lignes" class="num">{{ $transfer->lines->count() }}</td>
                            <td data-l="Demandé par">{{ $transfer->requested_by_name ?? '—' }}</td>
                            <td data-l="État"><span class="badge {{ $transfer->statusTone() }}">{{ $transfer->statusLabel() }}</span></td>
                            <td data-l="" class="acts"><a class="btn ghost sm" href="{{ route('pharmacie.transfers.show', $transfer) }}">Ouvrir</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-pharmacie::card>
@endsection
