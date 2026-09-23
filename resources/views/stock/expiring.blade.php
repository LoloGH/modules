@extends('pharmacie::layout')

@section('title', 'Péremptions')

@section('content')
    <x-pharmacie::page title="Péremptions"
        :sub="'Les lots qui périment dans les '.$days.' prochains jours, et ceux qui le sont déjà.'" />

    <div class="kpis">
        <x-pharmacie::kpi label="Lignes concernées" icon="alert" tone="amber" :value="(string) $stocks->count()" />
        <x-pharmacie::kpi label="Déjà périmés" icon="alert" tone="red" :value="(string) $expired->count()" foot="Non délivrables" />
        <x-pharmacie::kpi label="Valeur concernée" icon="lot" tone="violet" :value="$money($value)" foot="Au prix d'achat" />
    </div>

    <x-pharmacie::card flush>
        <div class="bd">
            <form method="get" action="{{ route('pharmacie.stock.expiring') }}" class="inline">
                <label>Horizon (jours) <input name="jours" inputmode="numeric" value="{{ $days }}"></label>
                <button type="submit" class="ghost sm">Voir</button>
            </form>
        </div>

        @if ($stocks->isEmpty())
            <div class="bd">
                <x-pharmacie::empty title="Rien ne périme à cet horizon" icon="check">
                    Élargissez l'horizon pour anticiper davantage.
                </x-pharmacie::empty>
            </div>
        @else
            <div class="tw">
                <table class="stack wide">
                    <thead>
                    <tr><th>Produit</th><th>Lot</th><th>Périme le</th><th>Emplacement</th><th class="num">Quantité</th><th class="num">Valeur</th><th>État</th></tr>
                    </thead>
                    <tbody>
                    @foreach ($stocks as $stock)
                        @php($batch = $stock->batch)
                        <tr class="{{ $batch?->isExpired() ? 'cancelled' : '' }}">
                            <td data-l="Produit" class="strong">
                                {{ $batch?->product?->label() }}
                                <span class="sub mono">{{ $batch?->product?->code }}</span>
                            </td>
                            <td data-l="Lot" class="mono">
                                <a href="{{ route('pharmacie.stock.batches.show', $batch) }}">{{ $batch?->number }}</a>
                            </td>
                            <td data-l="Périme le">
                                {{ $batch?->expires_on?->format('d/m/Y') }}
                                <span class="sub">
                                    @if ($batch?->daysToExpiry() < 0)
                                        depuis {{ abs($batch->daysToExpiry()) }} jour(s)
                                    @else
                                        dans {{ $batch?->daysToExpiry() }} jour(s)
                                    @endif
                                </span>
                            </td>
                            <td data-l="Emplacement">{{ $stock->location?->name }}</td>
                            <td data-l="Quantité" class="num strong">{{ $stock->quantity }}</td>
                            <td data-l="Valeur" class="num">{{ $money((int) $stock->quantity * (int) ($batch?->purchase_price ?? 0)) }}</td>
                            <td data-l="État"><span class="badge {{ $batch?->statusTone() }}">{{ $batch?->statusLabel() }}</span></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-pharmacie::card>
@endsection
