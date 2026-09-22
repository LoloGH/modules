@extends('finance::layout')

@section('title', 'Caisses')

@section('content')
    <x-finance::page title="Caisses" sub="Les postes d'encaissement de l'établissement. Une caisse se désactive, elle ne se supprime pas." />

    <x-finance::card title="Nouvelle caisse">
        <form method="post" action="{{ route('finance.registers.store') }}">
            @csrf
            <div class="row">
                <label>Code <input name="code" value="{{ old('code') }}" placeholder="CAISSE-TICKET" required></label>
                <label>Nom <input name="name" value="{{ old('name') }}" placeholder="Caisse Ticket" required></label>
            </div>
            <div class="actions">
                <button type="submit"><x-finance::icon name="plus" /> Créer la caisse</button>
            </div>
        </form>
    </x-finance::card>

    <x-finance::card title="Caisses de l'établissement" hint="{{ $registers->count() }} caisse(s)" flush>
        @if ($registers->isEmpty())
            <div class="bd">
                <x-finance::empty title="Aucune caisse" icon="caisse">
                    Créez-en une pour que les caissiers puissent ouvrir leur session.
                </x-finance::empty>
            </div>
        @else
            <div class="tw">
                <table class="stack">
                    <thead><tr><th>Code</th><th>Nom</th><th>État</th><th>Session ouverte</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($registers as $register)
                        <tr>
                            <td data-l="Code" class="mono">{{ $register->code }}</td>
                            <td data-l="Nom" class="strong">{{ $register->name }}</td>
                            <td data-l="État">
                                <span class="badge {{ $register->is_active ? 'ok' : 'off' }}">{{ $register->is_active ? 'Active' : 'Désactivée' }}</span>
                            </td>
                            <td data-l="Session ouverte">{{ $register->open_sessions_count > 0 ? 'Oui' : 'Non' }}</td>
                            <td data-l="" class="acts">
                                <form method="post" action="{{ route('finance.registers.toggle', $register) }}">@csrf
                                    <button type="submit" class="ghost sm">{{ $register->is_active ? 'Désactiver' : 'Activer' }}</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-finance::card>

    <x-finance::card title="Caissiers"
                     hint="Défaut de l'établissement : {{ $defaultLimit }} session(s)">
        <p class="muted">
            Combien de sessions un caissier peut tenir ouvertes, et sur quelles caisses.
            Limite vide = le défaut de l'établissement. Aucune caisse cochée = toutes
            les caisses. Une caisse reste toujours tenue par une seule personne à la fois.
        </p>

        @if ($cashiers === [])
            <x-finance::empty title="Aucun caissier connu" icon="caisse">
                L'application hôte ne déclare aucun personnel habilité à encaisser,
                et personne n'a encore ouvert de session.
            </x-finance::empty>
        @else
            <div class="tw">
                <table class="stack wide">
                    <thead>
                    <tr><th>Caissier</th><th class="num">Ouvertes</th><th style="width:7rem">Limite</th><th>Caisses autorisées</th><th></th></tr>
                    </thead>
                    <tbody>
                    @foreach ($cashiers as $cashier)
                        <tr>
                            <td data-l="Caissier" class="strong">
                                {{ $cashier['name'] }}
                                <span class="sub">
                                    @if ($cashier['function']){{ $cashier['function'] }} · @endif
                                    {{ $cashier['override'] === null ? 'Limite par défaut' : 'Limite propre' }}
                                    · {{ $cashier['registers'] === [] ? 'Toutes les caisses' : count($cashier['registers']).' caisse(s)' }}
                                </span>
                            </td>
                            <td data-l="Ouvertes" class="num">{{ $cashier['open'] }} / {{ $cashier['limit'] }}</td>
                            <td data-l="Limite">
                                <input form="acces-{{ $loop->index }}" name="max_open_sessions" inputmode="numeric"
                                       value="{{ $cashier['override'] }}" placeholder="{{ $defaultLimit }}"
                                       aria-label="Limite de {{ $cashier['name'] }}">
                            </td>
                            <td data-l="Caisses autorisées">
                                @if ($assignable->isEmpty())
                                    <span class="muted">Aucune caisse active.</span>
                                @else
                                    <div class="checks">
                                        @foreach ($assignable as $register)
                                            <label>
                                                <input form="acces-{{ $loop->parent->index }}" type="checkbox"
                                                       name="registers[]" value="{{ $register->id }}"
                                                       @checked(in_array($register->id, $cashier['registers'], true))>
                                                {{ $register->name }}
                                            </label>
                                        @endforeach
                                    </div>
                                @endif
                            </td>
                            <td data-l="" class="acts">
                                <form id="acces-{{ $loop->index }}" method="post" action="{{ route('finance.registers.access.store') }}">
                                    @csrf
                                    <input type="hidden" name="cashier_id" value="{{ $cashier['id'] }}">
                                    <button type="submit" class="ghost sm">Enregistrer</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-finance::card>
@endsection
