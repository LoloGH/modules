@extends('finance::layout')

@section('title', 'Ma caisse')

@section('content')
    <h1>Ma caisse</h1>
    <p class="muted">Vous n'avez pas de session ouverte.</p>

    @can('finance.sessions.open')
        <section class="card">
            <h2>Ouvrir ma session</h2>
            @if ($registers->isEmpty())
                <p>Aucune caisse active. Demandez à un administrateur d'en créer une.</p>
            @else
                <form method="post" action="{{ route('finance.cash.sessions.open') }}">
                    @csrf
                    <label>Caisse
                        <select name="cash_register_id" required>
                            @foreach ($registers as $register)
                                <option value="{{ $register->id }}" @selected((string) old('cash_register_id') === (string) $register->id)>{{ $register->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Fonds initial (FCFA)
                        <input name="opening_float" inputmode="numeric" value="{{ old('opening_float', 0) }}" required>
                    </label>
                    <button type="submit">Ouvrir la session</button>
                </form>
            @endif
        </section>
    @endcan

    <section class="card">
        <h2>Mes dernières sessions</h2>
        @if ($recent->isEmpty())
            <p class="muted">Aucune session pour l'instant.</p>
        @else
            <table>
                <thead><tr><th>Session</th><th>Caisse</th><th>Ouverte le</th><th>Statut</th><th class="num">Écart</th><th></th></tr></thead>
                <tbody>
                @foreach ($recent as $item)
                    <tr>
                        <td>{{ $item->number }}</td>
                        <td>{{ $item->register->name }}</td>
                        <td>{{ $item->opened_at?->format('d/m/Y H:i') }}</td>
                        <td><span class="badge {{ $item->status }}">{{ $item->statusLabel() }}</span></td>
                        <td class="num">{{ $item->variance === null ? '—' : $money($item->variance) }}</td>
                        <td><a href="{{ route('finance.cash.sessions.show', $item) }}">Voir</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </section>
@endsection
