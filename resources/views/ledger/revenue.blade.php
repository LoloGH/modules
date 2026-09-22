@extends('finance::layout')

@section('title', 'Recettes')

@section('content')
    <x-finance::page title="Recettes"
        sub="Ce qu'ont rapporté les encaissements {{ $filters->periodLabel() }}{{ $scoped ? ', de vos sessions' : ', de toutes les caisses' }}." />

    <div class="kpis">
        <x-finance::kpi label="Recettes de la période" icon="recette" tone="green" :value="$money($total)" foot="Encaissements valides" />
        <x-finance::kpi label="Encaissements" icon="paiement" tone="blue" :value="(string) $count" />
        <x-finance::kpi label="Premier service" icon="centre" tone="violet"
                        :value="$byCenter->isEmpty() ? '—' : $byCenter->keys()->first()"
                        :foot="$byCenter->isEmpty() ? null : $money($byCenter->first())" />
    </div>

    <div class="cols wide">
        <x-finance::card flush>
            <div class="bd">
                <form method="get" action="{{ route('finance.ledger.revenue') }}">
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
                        <label>Service (centre analytique)
                            <select name="centre">
                                <option value="">Tous</option>
                                @foreach ($centers as $center)
                                    <option value="{{ $center->id }}" @selected($filters->centerId === $center->id)>{{ $center->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>Activité (acte)
                            <select name="acte">
                                <option value="">Toutes</option>
                                @foreach ($acts as $act)
                                    <option value="{{ $act->id }}" @selected($filters->actId === $act->id)>{{ $act->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>Recherche <input name="q" value="{{ $filters->search }}" placeholder="N°, patient, référence…"></label>
                    </div>
                    <div class="actions">
                        <button type="submit" class="sm">Filtrer</button>
                        <a class="btn ghost sm" href="{{ route('finance.ledger.revenue') }}">Réinitialiser</a>
                    </div>
                </form>
            </div>

            @if ($items->isEmpty())
                <div class="bd">
                    <x-finance::empty title="Aucune recette pour ces critères" icon="recette">Élargissez la période ou retirez un filtre.</x-finance::empty>
                </div>
            @else
                <div class="tw">
                    <table class="stack wide">
                        <thead>
                        <tr><th>Date</th><th>Source</th><th>Service / activité</th><th>Moyen</th><th>Référence</th><th class="num">Montant</th></tr>
                        </thead>
                        <tbody>
                        @foreach ($items as $payment)
                            <tr>
                                <td data-l="Date">{{ $payment->created_at?->format('d/m/Y H:i') }} <span class="sub mono">{{ $payment->number }}</span></td>
                                <td data-l="Source" class="strong">
                                    {{ $payment->act?->name ?? $payment->description ?? 'Encaissement' }}
                                    @if ($payment->patient_name || $payment->patient_id)
                                        <span class="sub">{{ $payment->patient_name }}@if ($payment->patient_name && $payment->patient_id) · @endif{{ $payment->patient_id }}</span>
                                    @endif
                                </td>
                                <td data-l="Service / activité">
                                    {{ $payment->act?->center?->name ?? ($payment->act ? 'Sans centre analytique' : 'Hors catalogue') }}
                                    @if ($payment->act) <span class="sub">{{ $payment->act->name }}</span> @endif
                                </td>
                                <td data-l="Moyen">{{ $payment->method?->name }}</td>
                                <td data-l="Référence" class="mono">{{ $payment->reference ?? '—' }}</td>
                                <td data-l="Montant" class="num strong">{{ $money($payment->amount) }}</td>
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

        <x-finance::card title="Par service" hint="Centre analytique de l'acte">
            @if ($byCenter->isEmpty())
                <p class="muted" style="margin:0">Aucune recette sur la période.</p>
            @else
                <dl class="facts">
                    @foreach ($byCenter as $name => $amount)
                        <div class="f"><dt>{{ $name }}</dt><dd>{{ $money($amount) }}</dd></div>
                    @endforeach
                    <div class="f total"><dt>Total</dt><dd>{{ $money($total) }}</dd></div>
                </dl>
            @endif
        </x-finance::card>
    </div>
@endsection
