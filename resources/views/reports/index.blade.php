@extends('pharmacie::layout')

@section('title', 'Rapports')

@section('content')
    <x-pharmacie::page title="Rapports"
        sub="Ce qui a été consommé, ce qui est immobilisé, ce qui a été perdu.">
        <x-slot:actions>
            <a class="btn ghost sm" href="{{ route('pharmacie.reports.forecast', request()->query()) }}">
                <x-pharmacie::icon name="rapport" /> Prévision
            </a>
            <a class="btn ghost sm" href="{{ route('pharmacie.reports.export', request()->query() + ['quoi' => 'consommation']) }}">
                <x-pharmacie::icon name="document" /> Exporter
            </a>
        </x-slot:actions>
    </x-pharmacie::page>

    @include('pharmacie::reports.filters')

    <div class="kpis">
        <x-pharmacie::kpi label="Consommation" icon="dispensation" tone="violet"
            :value="(string) $summary['consumed_units']"
            :foot="$money($summary['consumed_value']).' au prix d\'achat'" />
        <x-pharmacie::kpi label="Valeur du stock" icon="lot" tone="green"
            :value="$money($summary['stock_value'])" foot="Au prix d'achat des lots" />
        <x-pharmacie::kpi label="Pertes" icon="alert" tone="red"
            :value="$money($summary['loss_value'])"
            :foot="$summary['expiry_rate'] === null ? 'Aucune entrée sur la période' : 'Soit '.number_format($summary['expiry_rate'] * 100, 1, ',', ' ').' % de ce qui est entré, périmé'" />
        <x-pharmacie::kpi label="Rotation annualisée" icon="rapport" tone="blue"
            :value="$summary['turnover'] === null ? '—' : number_format($summary['turnover'], 1, ',', ' ').' ×'"
            foot="Au rythme de la période" />
    </div>

    <div class="kpis">
        <x-pharmacie::kpi label="Produits en rupture" icon="alert" tone="amber"
            :value="(string) $summary['out_of_stock']"
            :foot="$summary['shortage_rate'] === null ? 'Aucun produit actif' : number_format($summary['shortage_rate'] * 100, 1, ',', ' ').' % du catalogue actif'" />
        <x-pharmacie::kpi label="Sous le seuil" icon="produit" tone="amber"
            :value="(string) $summary['below_threshold']" foot="À commander avant la rupture" />
        <x-pharmacie::kpi label="Dispensations" icon="dispensation" tone="blue"
            :value="(string) $summary['dispensations']"
            :foot="$summary['shortfalls'].' avec un reliquat'" />
        <x-pharmacie::kpi label="Délivré aux patients" icon="paiement" tone="green"
            :value="$money($summary['dispensations_value'])" foot="Au prix de vente" />
    </div>

    <x-pharmacie::card title="Ce qui est le plus consommé" hint="25 premiers produits" flush>
        @if ($byProduct->isEmpty())
            <div class="bd">
                <x-pharmacie::empty title="Rien n'est sorti sur cette période" icon="vide">
                    Changez la période, ou vérifiez les filtres.
                </x-pharmacie::empty>
            </div>
        @else
            <div class="tw">
                <table class="stack wide">
                    <thead><tr><th>Produit</th><th>Catégorie</th><th class="num">Quantité</th><th class="num">Valeur</th></tr></thead>
                    <tbody>
                    @foreach ($byProduct as $row)
                        <tr>
                            <td data-l="Produit" class="strong">
                                <a href="{{ route('pharmacie.stock.products.show', $row['product']) }}">{{ $row['product']->label() }}</a>
                            </td>
                            <td data-l="Catégorie">{{ $row['product']->category?->name ?? '—' }}</td>
                            <td data-l="Quantité" class="num">{{ $row['quantity'] }}</td>
                            <td data-l="Valeur" class="num">{{ $money($row['value']) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-pharmacie::card>

    <x-pharmacie::card title="Par catégorie" flush>
        @if ($byCategory->isEmpty())
            <div class="bd"><p class="muted" style="margin:0">Aucune consommation sur la période.</p></div>
        @else
            <div class="tw">
                <table class="stack wide">
                    <thead><tr><th>Catégorie</th><th class="num">Quantité</th><th class="num">Valeur</th></tr></thead>
                    <tbody>
                    @foreach ($byCategory as $row)
                        <tr>
                            <td data-l="Catégorie" class="strong">{{ $row['label'] }}</td>
                            <td data-l="Quantité" class="num">{{ $row['quantity'] }}</td>
                            <td data-l="Valeur" class="num">{{ $money($row['value']) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-pharmacie::card>

    <x-pharmacie::card title="Pertes de la période" hint="Ce qui est sorti sans être délivré" flush>
        @if ($losses === [])
            <div class="bd">
                <x-pharmacie::empty title="Aucune perte" icon="check">
                    Rien n'est sorti du stock sans avoir été délivré.
                </x-pharmacie::empty>
            </div>
        @else
            <div class="tw">
                <table class="stack wide">
                    <thead><tr><th>Nature</th><th class="num">Quantité</th><th class="num">Valeur</th></tr></thead>
                    <tbody>
                    @foreach ($losses as $row)
                        <tr>
                            <td data-l="Nature" class="strong">{{ $row['label'] }}</td>
                            <td data-l="Quantité" class="num">{{ $row['quantity'] }}</td>
                            <td data-l="Valeur" class="num">{{ $money($row['value']) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-pharmacie::card>
@endsection
