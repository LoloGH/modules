@extends('finance::layout')

@section('title', 'Caisses')

@section('content')
    <h1>Caisses</h1>

    <section class="card">
        <h2>Nouvelle caisse</h2>
        <form method="post" action="{{ route('finance.registers.store') }}">
            @csrf
            <label>Code (ex. CAISSE-TICKET) <input name="code" value="{{ old('code') }}" required></label>
            <label>Nom (ex. Caisse Ticket) <input name="name" value="{{ old('name') }}" required></label>
            <button type="submit">Créer la caisse</button>
        </form>
    </section>

    <section class="card">
        <h2>Caisses de l'établissement</h2>
        @if ($registers->isEmpty())
            <p class="muted">Aucune caisse.</p>
        @else
            <table>
                <thead><tr><th>Code</th><th>Nom</th><th>État</th><th>Session ouverte</th><th></th></tr></thead>
                <tbody>
                @foreach ($registers as $register)
                    <tr>
                        <td>{{ $register->code }}</td>
                        <td>{{ $register->name }}</td>
                        <td>{{ $register->is_active ? 'Active' : 'Désactivée' }}</td>
                        <td>{{ $register->open_sessions_count > 0 ? 'Oui' : 'Non' }}</td>
                        <td>
                            <form method="post" action="{{ route('finance.registers.toggle', $register) }}">@csrf
                                <button type="submit" class="secondary">{{ $register->is_active ? 'Désactiver' : 'Activer' }}</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </section>
@endsection
