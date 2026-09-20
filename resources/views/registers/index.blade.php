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
@endsection
