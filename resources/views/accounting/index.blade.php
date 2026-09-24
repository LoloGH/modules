@extends('finance::layout')

@section('title', 'Exports comptables')

@section('content')
    <x-finance::page title="Exports comptables"
        sub="Les écritures {{ $filters->periodLabel() }}, en partie double, telles que le comptable les reprendra." />

    <div class="kpis">
        <x-finance::kpi label="Écritures" icon="document" tone="blue" :value="(string) $count" :foot="$filters->periodLabel()" />
        <x-finance::kpi label="Total débit" icon="recette" tone="green" :value="$money($totals['debit'])" />
        <x-finance::kpi label="Total crédit" icon="depense" tone="violet" :value="$money($totals['credit'])" />
        <x-finance::kpi label="Équilibre" icon="controle" :tone="$totals['balanced'] ? 'green' : 'red'"
                        :value="$totals['balanced'] ? 'Équilibré' : 'Déséquilibré'"
                        :foot="$totals['balanced'] ? 'Débit = crédit' : 'Écart de '.$money(abs($totals['debit'] - $totals['credit']))" />
    </div>

    <x-finance::card flush>
        <div class="bd">
            <form method="get" action="{{ route('finance.accounting.index') }}">
                <div class="row">
                    <label>Du <input type="date" name="du" value="{{ $filters->from->toDateString() }}"></label>
                    <label>Au <input type="date" name="au" value="{{ $filters->to->toDateString() }}"></label>
                </div>
                <div class="actions">
                    <button type="submit"><x-finance::icon name="check" /> Voir la période</button>
                    <a class="btn ghost" href="{{ route('finance.accounting.journal', request()->query()) }}">
                        <x-finance::icon name="document" /> Télécharger le journal
                    </a>
                    <a class="btn ghost" href="{{ route('finance.accounting.balance', request()->query()) }}">
                        <x-finance::icon name="rapport" /> Télécharger la balance
                    </a>
                </div>
            </form>
        </div>
    </x-finance::card>

    <x-finance::card title="Balance de la période" hint="{{ count($balance) }} compte(s)" flush>
        @if ($balance === [])
            <div class="bd">
                <x-finance::empty title="Aucune écriture sur la période" icon="document">
                    Élargissez la période : rien n'a été encaissé, facturé ni décaissé.
                </x-finance::empty>
            </div>
        @else
            <div class="tw">
                <table class="stack">
                    <thead><tr><th>Compte</th><th>Libellé</th><th class="num">Débit</th><th class="num">Crédit</th><th class="num">Solde</th></tr></thead>
                    <tbody>
                    @foreach ($balance as $row)
                        <tr>
                            <td data-l="Compte" class="mono strong">{{ $row['account'] }}</td>
                            <td data-l="Libellé">{{ $row['label'] }}</td>
                            <td data-l="Débit" class="num">{{ $money($row['debit']) }}</td>
                            <td data-l="Crédit" class="num">{{ $money($row['credit']) }}</td>
                            <td data-l="Solde" class="num strong">{{ $money($row['balance']) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-finance::card>

    <x-finance::card title="Journal" hint="{{ min($count, $preview) }} première(s) écriture(s) sur {{ $count }}" flush>
        @if ($entries === [])
            <div class="bd">
                <p class="muted" style="margin:0">Rien à exporter sur cette période.</p>
            </div>
        @else
            <div class="tw">
                <table class="stack wide">
                    <thead>
                    <tr><th>Date</th><th>Journal</th><th>Pièce</th><th>Compte</th><th>Libellé</th><th class="num">Débit</th><th class="num">Crédit</th><th>Centre</th></tr>
                    </thead>
                    <tbody>
                    @foreach ($entries as $entry)
                        <tr>
                            <td data-l="Date">{{ $entry->date->format('d/m/Y') }}</td>
                            <td data-l="Journal" class="mono">{{ $entry->journal }}</td>
                            <td data-l="Pièce" class="mono">{{ $entry->piece }}</td>
                            <td data-l="Compte" class="mono strong">{{ $entry->account }}</td>
                            <td data-l="Libellé">{{ $entry->label }}</td>
                            <td data-l="Débit" class="num">{{ $entry->debit === 0 ? '' : $money($entry->debit) }}</td>
                            <td data-l="Crédit" class="num">{{ $entry->credit === 0 ? '' : $money($entry->credit) }}</td>
                            <td data-l="Centre">{{ $entry->center ?? '-' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-finance::card>

    <p class="muted">
        Les comptes se règlent dans « Paramètres financiers », et chaque centre
        analytique peut porter le sien pour ses produits. Les opérations annulées
        ne partent jamais en comptabilité.
    </p>
@endsection
