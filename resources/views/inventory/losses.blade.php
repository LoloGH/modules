@extends('pharmacie::layout')

@section('title', 'Pertes et destructions')

@section('content')
    <x-pharmacie::page title="Pertes et destructions"
        sub="Ce qui sort du stock sans avoir été délivré : périmé, cassé, volé, détérioré." />

    <div class="kpis">
        <x-pharmacie::kpi label="Valeur des pertes" icon="depense" tone="red" :value="$money($total)" foot="Au prix d'achat" />
        <x-pharmacie::kpi label="Lots périmés en stock" icon="alert" tone="amber" :value="(string) $expired->count()" foot="À détruire" />
    </div>

    @can('pharmacie.stock.adjust')
        <x-pharmacie::card title="Enregistrer une perte ou une destruction" hint="Toujours un motif ; une destruction se fait devant témoin">
            @if ($stocks->isEmpty())
                <p class="muted" style="margin:0">Aucun stock : rien à sortir.</p>
            @else
                <form method="post" action="{{ route('pharmacie.inventory.losses.store') }}">
                    @csrf
                    <div class="row">
                        <label>Lot
                            <select name="batch_id" required>
                                @foreach ($stocks as $stock)
                                    <option value="{{ $stock->batch_id }}">
                                        {{ $stock->batch?->product?->label() }} · lot {{ $stock->batch?->number }}
                                        @if ($stock->batch?->expires_on) (périme le {{ $stock->batch->expires_on->format('d/m/Y') }}) @endif
                                        — {{ $stock->quantity }} à {{ $stock->location?->name }}
                                    </option>
                                @endforeach
                            </select>
                        </label>
                        <label>Emplacement
                            <select name="location_id" required>
                                @foreach ($stocks->pluck('location')->unique('id')->filter() as $location)
                                    <option value="{{ $location->id }}">{{ $location->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>Nature
                            <select name="kind" required>
                                @foreach ($kinds as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>Quantité <input name="quantity" inputmode="numeric" required></label>
                    </div>
                    <div class="row">
                        <label>Motif <input name="reason" placeholder="ex. lot périmé, retiré du comptoir" required></label>
                        <label>Témoin <input name="witness" placeholder="obligatoire pour une destruction"></label>
                    </div>
                    <div class="checks">
                        <label><input type="checkbox" name="destroyed" value="1"> Produit physiquement détruit</label>
                    </div>
                    <div class="actions">
                        <button type="submit" class="danger"><x-pharmacie::icon name="alert" /> Enregistrer</button>
                    </div>
                </form>
            @endif
        </x-pharmacie::card>
    @endcan

    <x-pharmacie::card title="Pertes enregistrées" hint="{{ $losses->total() }} au total" flush>
        @if ($losses->isEmpty())
            <div class="bd">
                <x-pharmacie::empty title="Aucune perte" icon="check">
                    Rien n'est sorti du stock sans avoir été délivré.
                </x-pharmacie::empty>
            </div>
        @else
            <div class="tw">
                <table class="stack wide">
                    <thead><tr><th>N°</th><th>Produit</th><th>Lot</th><th>Nature</th><th class="num">Quantité</th><th class="num">Valeur</th><th>Motif</th><th>Par</th></tr></thead>
                    <tbody>
                    @foreach ($losses as $loss)
                        <tr>
                            <td data-l="N°" class="mono strong">
                                {{ $loss->number }}
                                @if ($loss->destroyed) <span class="badge danger">Détruit</span> @endif
                            </td>
                            <td data-l="Produit">{{ $loss->batch?->product?->label() }}</td>
                            <td data-l="Lot" class="mono">{{ $loss->batch?->number }}</td>
                            <td data-l="Nature">{{ $loss->kindLabel() }}</td>
                            <td data-l="Quantité" class="num">{{ $loss->quantity }}</td>
                            <td data-l="Valeur" class="num">{{ $money($loss->value) }}</td>
                            <td data-l="Motif">
                                {{ $loss->reason }}
                                @if ($loss->witness) <span class="sub">témoin : {{ $loss->witness }}</span> @endif
                            </td>
                            <td data-l="Par">{{ $loss->recorded_by_name ?? '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-pharmacie::card>
@endsection
