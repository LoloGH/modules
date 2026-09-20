@extends('finance::layout')

@section('title', 'Caisse')

@section('content')
    <x-finance::page title="Ma caisse" sub="Ouvrez votre session pour encaisser et décaisser." />

    <div class="cols wide">
        @can('finance.sessions.open')
            <x-finance::card title="Ouvrir ma session" hint="Une session par caisse et par caissier">
                @if ($registers->isEmpty())
                    <x-finance::empty title="Aucune caisse active" icon="caisse">
                        Demandez à un administrateur d'en créer une avant d'ouvrir votre session.
                    </x-finance::empty>
                @else
                    <form method="post" action="{{ route('finance.cash.sessions.open') }}">
                        @csrf
                        <div class="row">
                            <label>Caisse
                                <select name="cash_register_id" required>
                                    @foreach ($registers as $register)
                                        <option value="{{ $register->id }}" @selected((string) old('cash_register_id') === (string) $register->id)>{{ $register->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label>Fonds initial
                                <input name="opening_float" class="money" inputmode="numeric" value="{{ old('opening_float', 0) }}" required>
                                <span class="help">En FCFA, ce que contient le tiroir à l'ouverture.</span>
                            </label>
                        </div>
                        <div class="actions">
                            <button type="submit" class="lg"><x-finance::icon name="caisse" /> Ouvrir la session</button>
                        </div>
                    </form>
                @endif
            </x-finance::card>
        @endcan

        <x-finance::card title="Comment ça marche">
            <dl class="facts">
                <div class="f"><dt>1. Ouverture</dt><dd>Fonds initial</dd></div>
                <div class="f"><dt>2. Journée</dt><dd>Encaissements, décaissements</dd></div>
                <div class="f"><dt>3. Clôture</dt><dd>Comptage et écart</dd></div>
                <div class="f"><dt>4. Contrôle</dt><dd>Validation par un tiers</dd></div>
            </dl>
            <p class="muted" style="margin-bottom:0">
                Seules les espèces entrent dans le tiroir. Le Mobile Money, la carte ou le
                virement sont totalisés à part. Un caissier ne valide jamais sa propre clôture.
            </p>
        </x-finance::card>
    </div>

    <x-finance::card title="Mes dernières sessions" flush>
        @if ($recent->isEmpty())
            <div class="bd">
                <x-finance::empty title="Aucune session pour l'instant" icon="horloge">
                    Vos sessions passées et leurs écarts s'afficheront ici.
                </x-finance::empty>
            </div>
        @else
            <div class="tw">
                <table class="stack">
                    <thead>
                    <tr><th>Session</th><th>Caisse</th><th>Ouverte le</th><th>Statut</th><th class="num">Écart</th><th></th></tr>
                    </thead>
                    <tbody>
                    @foreach ($recent as $item)
                        <tr>
                            <td data-l="Session" class="mono">{{ $item->number }}</td>
                            <td data-l="Caisse">{{ $item->register->name }}</td>
                            <td data-l="Ouverte le">{{ $item->opened_at?->format('d/m/Y H:i') }}</td>
                            <td data-l="Statut"><span class="badge {{ $item->status }}">{{ $item->statusLabel() }}</span></td>
                            <td data-l="Écart" class="num">
                                @if ($item->variance === null) —
                                @else <span class="{{ $item->variance < 0 ? 'neg' : ($item->variance > 0 ? 'pos' : 'zero') }}">{{ $item->variance > 0 ? '+' : '' }}{{ $money($item->variance) }}</span>
                                @endif
                            </td>
                            <td data-l="" class="acts">
                                <a class="btn ghost sm" href="{{ route('finance.cash.sessions.show', $item) }}">Voir</a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-finance::card>
@endsection
