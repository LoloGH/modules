@extends('finance::layout')

@section('title', 'Dépenses')

@section('content')
    <x-finance::page title="Dépenses"
        sub="Les décaissements {{ $filters->periodLabel() }}{{ $scoped ? ', de vos sessions' : ', de toutes les caisses' }}." />

    <div class="kpis">
        <x-finance::kpi label="Dépenses de la période" icon="depense" tone="red" :value="$money($stats['total'])" foot="Décaissements valides" />
        <x-finance::kpi label="Décaissements" icon="paiement" tone="blue" :value="(string) $stats['count']" />
        <x-finance::kpi label="Annulés" icon="alert" tone="amber" :value="(string) $stats['cancelled']" foot="Hors du total" />
    </div>

    <div class="cols wide">
        <x-finance::card flush>
            <div class="bd">
                <form method="get" action="{{ route('finance.ledger.expenses') }}">
                    <div class="row">
                    <label>Du <input type="date" name="du" value="{{ $filters->from->toDateString() }}"></label>
                    <label>Au <input type="date" name="au" value="{{ $filters->to->toDateString() }}"></label>
                    <label>Moyen de paiement
                        <select name="moyen">
                            <option value="">Tous</option>
                            @foreach ($methods as $method)
                                <option value="{{ $method->id }}" @selected($filters->methodId === $method->id)>{{ $method->name }}</option>
                            @endforeach
                        </select>
                    </label>
                        <label>Catégorie
                            <select name="categorie">
                                <option value="">Toutes</option>
                                @foreach ($categories as $code => $label)
                                    <option value="{{ $code }}" @selected($category === $code)>{{ $label }}</option>
                                @endforeach
                                <option value="aucune" @selected($category === 'aucune')>Non classées</option>
                            </select>
                        </label>
                        <label>Statut
                            <select name="statut">
                                <option value="tous">Tous</option>
                                <option value="valid" @selected($filters->status === 'valid')>Valides</option>
                                <option value="cancelled" @selected($filters->status === 'cancelled')>Annulés</option>
                            </select>
                        </label>
                        <label>Recherche <input name="q" value="{{ $filters->search }}" placeholder="N°, motif, bénéficiaire, référence…"></label>
                    </div>
                    <div class="actions">
                        <button type="submit" class="sm">Filtrer</button>
                        <a class="btn ghost sm" href="{{ route('finance.ledger.expenses') }}">Réinitialiser</a>
                    </div>
                </form>
            </div>

            @if ($items->isEmpty())
                <div class="bd">
                    <x-finance::empty title="Aucune dépense pour ces critères" icon="depense">Élargissez la période ou retirez un filtre.</x-finance::empty>
                </div>
            @else
                <div class="tw">
                    <table class="stack wide">
                        <thead>
                        <tr><th>Date</th><th>Catégorie</th><th>Motif</th><th>Bénéficiaire</th><th>Moyen</th><th>Référence</th><th>Statut</th><th class="num">Montant</th><th></th></tr>
                        </thead>
                        <tbody>
                        @foreach ($items as $item)
                            <tr class="{{ $item->isCancelled() ? 'cancelled' : '' }}">
                                <td data-l="Date">{{ $item->created_at?->format('d/m/Y H:i') }} <span class="sub mono">{{ $item->number }}</span></td>
                                <td data-l="Catégorie">{{ $item->categoryLabel() }}</td>
                                <td data-l="Motif" class="strong">{{ $item->reason }}</td>
                                <td data-l="Bénéficiaire">{{ $item->beneficiary ?? '—' }}</td>
                                <td data-l="Moyen">{{ $item->method?->name }}</td>
                                <td data-l="Référence" class="mono">{{ $item->reference ?? '—' }}</td>
                                <td data-l="Statut">
                                    <span class="badge {{ $item->isCancelled() ? 'danger' : 'ok' }}">{{ $item->isCancelled() ? 'Annulé' : 'Valide' }}</span>
                                </td>
                                <td data-l="Montant" class="num strong">−{{ $money($item->amount) }}</td>
                                <td data-l="" class="acts">
                                    <a class="btn ghost sm" target="_blank" rel="noopener" href="{{ route('finance.cash.disbursements.receipt', $item) }}">Bon</a>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @if ($items->hasPages())
                <div class="bd pager">
                    @if ($items->previousPageUrl()) <a class="btn ghost sm" href="{{ $items->previousPageUrl() }}">← Précédentes</a> @endif
                    <span class="muted">Page {{ $items->currentPage() }} sur {{ $items->lastPage() }}</span>
                    @if ($items->nextPageUrl()) <a class="btn ghost sm" href="{{ $items->nextPageUrl() }}">Suivantes →</a> @endif
                </div>
            @endif
            @endif
        </x-finance::card>

        <x-finance::card title="Par catégorie">
            @if ($byCategory->isEmpty())
                <p class="muted" style="margin:0">Aucune dépense sur la période.</p>
            @else
                <dl class="facts">
                    @foreach ($byCategory as $name => $amount)
                        <div class="f"><dt>{{ $name }}</dt><dd>{{ $money($amount) }}</dd></div>
                    @endforeach
                    <div class="f gap"><dt>Total</dt><dd>{{ $money($stats['total']) }}</dd></div>
                </dl>
            @endif
        </x-finance::card>
    </div>
@endsection
