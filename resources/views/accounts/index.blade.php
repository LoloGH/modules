@extends('finance::layout')

@section('title', 'Comptes patients')

@section('content')
    <x-finance::page title="Comptes patients"
        sub="Les avances versées, ce qu'elles ont payé, et ce qu'il reste à chaque patient." />

    <div class="kpis">
        <x-finance::kpi label="Avances versées" icon="recette" tone="green" :value="$money($totals['deposited'])" />
        <x-finance::kpi label="Déjà utilisé" icon="paiement" tone="blue" :value="$money($totals['used'])" foot="Encaissements réglés sur compte" />
        <x-finance::kpi label="Solde des comptes" icon="caisse" tone="violet" :value="$money($totals['balance'])" foot="Dû aux patients" />
    </div>

    <x-finance::card flush>
        <div class="bd">
            <form method="get" action="{{ route('finance.accounts.index') }}" class="inline">
                <label class="sr" for="q">Rechercher</label>
                <input id="q" name="q" value="{{ $search }}" placeholder="Nom ou identifiant du patient">
                <button type="submit" class="ghost sm">Rechercher</button>
                @if ($search)
                    <a class="btn ghost sm" href="{{ route('finance.accounts.index') }}">Effacer</a>
                @endif
            </form>
        </div>

        @if ($accounts === [])
            <div class="bd">
                <x-finance::empty title="Aucun compte" icon="caisse">
                    Un patient a un compte dès qu'il verse une avance à la caisse.
                </x-finance::empty>
            </div>
        @else
            <div class="tw">
                <table class="stack">
                    <thead>
                    <tr><th>Patient</th><th class="num">Avances</th><th class="num">Utilisé</th><th class="num">Solde</th><th></th></tr>
                    </thead>
                    <tbody>
                    @foreach ($accounts as $account)
                        <tr>
                            <td data-l="Patient" class="strong">
                                {{ $account['patient_name'] ?? 'Patient' }}
                                <span class="sub mono">{{ $account['patient_id'] }}</span>
                            </td>
                            <td data-l="Avances" class="num">{{ $money($account['deposited']) }}</td>
                            <td data-l="Utilisé" class="num">{{ $money($account['used']) }}</td>
                            <td data-l="Solde" class="num strong">{{ $money($account['balance']) }}</td>
                            <td data-l="" class="acts">
                                <a class="btn ghost sm" href="{{ route('finance.accounts.show', $account['patient_id']) }}">Ouvrir le compte</a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-finance::card>
@endsection
