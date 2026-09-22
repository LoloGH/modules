@extends('finance::layout')

@section('title', 'Rapports')

@section('content')
    <x-finance::page title="Rapports" sub="{{ $types[$type] }} — {{ $filters->periodLabel() }}, tout l'établissement.">
        <x-slot:actions>
            <a class="btn ghost sm" href="{{ route('finance.reports.export', ['type' => $type] + $filters->query()) }}">
                <x-finance::icon name="rapport" /> Exporter (CSV)
            </a>
        </x-slot:actions>
    </x-finance::page>

    <div class="kpis">
        <x-finance::kpi label="Recettes de caisse" icon="recette" tone="green" :value="$money($summary['revenue'])" />
        <x-finance::kpi label="Règlements des assureurs" icon="assurance" tone="violet" :value="$money($summary['insurance'])" />
        <x-finance::kpi label="Dépenses" icon="depense" tone="red" :value="$money($summary['expenses'])" />
        <x-finance::kpi label="Solde de la période" icon="paiement" tone="blue" :value="$money($summary['net'])" foot="Recettes + règlements − dépenses" />
    </div>

    <x-finance::card>
        <form method="get" action="{{ route('finance.reports.index') }}">
            <div class="row">
                <label>Type de rapport
                    <select name="type">
                        @foreach ($types as $key => $label)
                            <option value="{{ $key }}" @selected($type === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label>Du <input type="date" name="du" value="{{ $filters->from->toDateString() }}"></label>
                <label>Au <input type="date" name="au" value="{{ $filters->to->toDateString() }}"></label>
            </div>
            <div class="row">
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
                <label>Moyen de paiement
                    <select name="moyen">
                        <option value="">Tous</option>
                        @foreach ($methods as $method)
                            <option value="{{ $method->id }}" @selected($filters->methodId === $method->id)>{{ $method->name }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
            <p class="help">Le service et l'activité filtrent les recettes ; le moyen, les recettes et les dépenses. Les règlements des assureurs sont datés du jour de réception.</p>
            <div class="actions">
                <button type="submit" class="sm">Afficher le rapport</button>
                <a class="btn ghost sm" href="{{ route('finance.reports.index') }}">Réinitialiser</a>
            </div>
        </form>
    </x-finance::card>

    <x-finance::card title="{{ $types[$type] }}" hint="{{ count($report['rows']) }} ligne(s)" flush>
        @if ($report['rows'] === [])
            <div class="bd">
                <x-finance::empty title="Rien sur cette période" icon="rapport">Élargissez la période ou retirez un filtre.</x-finance::empty>
            </div>
        @else
            <div class="tw">
                <table class="stack">
                    <thead>
                    <tr>
                        @foreach ($report['columns'] as $i => $column)
                            <th class="{{ $i > 0 ? 'num' : '' }}">{{ $column }}</th>
                        @endforeach
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($report['rows'] as $row)
                        <tr>
                            @foreach ($row as $i => $cell)
                                <td data-l="{{ $report['columns'][$i] }}" class="{{ $i === 0 ? 'strong' : 'num' }}">
                                    {{ in_array($i, $report['money'], true) ? $money((int) $cell) : $cell }}
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                    <tr>
                        @foreach ($report['total'] as $i => $cell)
                            <td data-l="{{ $report['columns'][$i] }}" class="strong {{ $i === 0 ? '' : 'num' }}">
                                {{ in_array($i, $report['money'], true) ? $money((int) $cell) : $cell }}
                            </td>
                        @endforeach
                    </tr>
                    </tbody>
                </table>
            </div>
        @endif
    </x-finance::card>
@endsection
