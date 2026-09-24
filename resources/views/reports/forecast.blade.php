@extends('pharmacie::layout')

@section('title', 'Prévision de réapprovisionnement')

@section('content')
    <x-pharmacie::page title="Prévision de réapprovisionnement"
        sub="Au rythme observé : ce qui tiendra, ce qui manquera, et de combien.">
        <x-slot:actions>
            <a class="btn ghost sm" href="{{ route('pharmacie.reports.index', request()->query()) }}">
                <x-pharmacie::icon name="rapport" /> Rapports
            </a>
            <a class="btn ghost sm" href="{{ route('pharmacie.reports.export', request()->query() + ['quoi' => 'prevision']) }}">
                <x-pharmacie::icon name="document" /> Exporter
            </a>
        </x-slot:actions>
    </x-pharmacie::page>

    @include('pharmacie::reports.filters')

    <x-pharmacie::card title="Comment ce besoin est calculé">
        <p class="muted" style="margin:0">
            La consommation moyenne par jour est celle de la période choisie. Le besoin couvre
            <strong>{{ $horizon }} jours</strong> d'horizon, plus <strong>{{ $lead }} jours</strong> de délai
            de livraison et <strong>{{ $safety }} jours</strong> de marge de sécurité ; on en retranche
            le stock et ce qui est déjà commandé. Un produit qu'on n'a pas consommé sur la période
            n'apparaît pas : on ne commande pas sur une moyenne qui n'existe pas.
            Ces trois durées se règlent dans la configuration du module.
        </p>
    </x-pharmacie::card>

    <x-pharmacie::card title="Produits à réapprovisionner" hint="Les plus menacés en premier" flush>
        @if ($rows->isEmpty())
            <div class="bd">
                <x-pharmacie::empty title="Aucune consommation observée" icon="vide">
                    Sans sortie sur la période, il n'y a pas de rythme à projeter.
                    Les seuils, eux, restent surveillés par les alertes.
                </x-pharmacie::empty>
            </div>
        @else
            <div class="tw">
                <table class="stack wide">
                    <thead><tr>
                        <th>Produit</th><th class="num">Par jour</th><th class="num">En stock</th>
                        <th class="num">En commande</th><th class="num">Couverture</th>
                        <th class="num">Besoin estimé</th><th>Risque</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td data-l="Produit" class="strong">
                                <a href="{{ route('pharmacie.stock.products.show', $row['product']) }}">{{ $row['product']->label() }}</a>
                            </td>
                            <td data-l="Par jour" class="num">{{ number_format($row['daily'], 2, ',', ' ') }}</td>
                            <td data-l="En stock" class="num">{{ $row['on_hand'] }}</td>
                            <td data-l="En commande" class="num">{{ $row['on_order'] ?: '—' }}</td>
                            <td data-l="Couverture" class="num">
                                {{ $row['coverage'] === null ? '—' : number_format($row['coverage'], 0, ',', ' ').' j' }}
                            </td>
                            <td data-l="Besoin estimé" class="num strong">{{ $row['needed'] ?: '—' }}</td>
                            <td data-l="Risque">
                                @php
                                    $tone = match ($row['risk']) {
                                        'rupture' => 'danger',
                                        'tendu' => 'warn',
                                        'suffisant' => 'ok',
                                        default => 'off',
                                    };
                                    $label = match ($row['risk']) {
                                        'rupture' => 'Rupture avant livraison',
                                        'tendu' => 'Tendu',
                                        'suffisant' => 'Suffisant',
                                        default => 'Inconnu',
                                    };
                                @endphp
                                <span class="badge {{ $tone }}">{{ $label }}</span>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-pharmacie::card>
@endsection
