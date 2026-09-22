@extends('finance::layout')

@section('title', $insurer->name)

@section('content')
    <a class="back" href="{{ route('finance.insurance.index') }}"><x-finance::icon name="retour" /> Retour aux assurances</a>

    <x-finance::page title="{{ $insurer->name }}" sub="{{ $insurer->kindLabel() }} · {{ $insurer->code }} · {{ $insurer->scopeLabel() }}, {{ $insurer->default_rate }} % par défaut">
        <x-slot:actions>
            <span class="badge {{ $insurer->is_active ? 'ok' : 'off' }}">{{ $insurer->is_active ? 'Actif' : 'Désactivé' }}</span>
        </x-slot:actions>
    </x-finance::page>

    @php($canManage = auth()->user()?->can('finance.insurance.manage'))

    <form method="post" action="{{ route('finance.insurers.coverage', $insurer) }}" data-coverage-editor>
        @csrf
        <x-finance::card title="Couverture">
            <div class="row">
                <label>Portée
                    <select name="coverage_scope" @disabled(! $canManage)>
                        <option value="all" @selected($insurer->coverage_scope === 'all')>Tous les actes, sauf ceux décochés</option>
                        <option value="selected" @selected($insurer->coverage_scope === 'selected')>Seulement les actes cochés</option>
                    </select>
                </label>
                <label>Taux par défaut (%)
                    <input name="default_rate" inputmode="numeric" value="{{ $insurer->default_rate }}" @disabled(! $canManage)>
                </label>
            </div>
            <p class="help">Un acte couvert sans taux propre prend le taux par défaut. Les factures déjà émises ne changent pas.</p>
        </x-finance::card>

        <x-finance::card title="Actes et prestations" hint="{{ $acts->count() }} acte(s) actif(s)" flush>
            <div class="tw">
                <table class="stack">
                    <thead><tr><th style="width:5rem">Couvert</th><th>Acte</th><th>Centre</th><th class="num">Tarif</th><th style="width:9rem">Taux propre (%)</th></tr></thead>
                    <tbody>
                    @foreach ($acts as $act)
                        @php($rule = array_key_exists($act->id, $rules) ? $rules[$act->id] : false)
                        @php($covered = $insurer->coverage_scope === 'all' ? $rule !== 0 : $rule !== false)
                        <tr>
                            <td data-l="Couvert">
                                <input type="checkbox" name="acts[{{ $act->id }}][covered]" value="1" @checked($covered) @disabled(! $canManage) aria-label="{{ $act->name }} couvert">
                            </td>
                            <td data-l="Acte" class="strong">{{ $act->name }} <span class="sub mono">{{ $act->code }}</span></td>
                            <td data-l="Centre">{{ $act->center?->name ?? '—' }}</td>
                            <td data-l="Tarif" class="num">{{ $act->standardTariff ? $money((int) $act->standardTariff->amount) : '—' }}</td>
                            <td data-l="Taux propre">
                                <input name="acts[{{ $act->id }}][rate]" inputmode="numeric"
                                       value="{{ is_int($rule) && $rule > 0 ? $rule : '' }}" placeholder="{{ $insurer->default_rate }}" @disabled(! $canManage)>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if ($canManage)
                <div class="bd actions">
                    <button type="submit"><x-finance::icon name="check" /> Enregistrer la couverture</button>
                </div>
            @endif
        </x-finance::card>
    </form>
@endsection
