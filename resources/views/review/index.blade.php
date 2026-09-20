@extends('finance::layout')

@section('title', 'Sessions à valider')

@section('content')
    <h1>Sessions de caisse</h1>
    <p>
        @foreach (['closed' => 'À valider', 'validated' => 'Validées', 'open' => 'Ouvertes', 'all' => 'Toutes'] as $key => $label)
            <a href="{{ route('finance.review.index', ['status' => $key]) }}" @if ($status === $key) style="font-weight:700" @endif>{{ $label }}</a>@if (! $loop->last) · @endif
        @endforeach
    </p>

    <section class="card">
        @if ($sessions->isEmpty())
            <p class="muted">Aucune session dans cette liste.</p>
        @else
            <table>
                <thead><tr><th>Session</th><th>Caisse</th><th>Caissier</th><th>Clôturée le</th><th class="num">Théorique</th><th class="num">Compté</th><th class="num">Écart</th><th>Statut</th><th></th></tr></thead>
                <tbody>
                @foreach ($sessions as $item)
                    <tr>
                        <td>{{ $item->number }}</td>
                        <td>{{ $item->register->name }}</td>
                        <td>{{ $item->cashier_name }}</td>
                        <td>{{ $item->closed_at?->format('d/m/Y H:i') ?? '—' }}</td>
                        <td class="num">{{ $item->expected_cash === null ? '—' : $money($item->expected_cash) }}</td>
                        <td class="num">{{ $item->counted_cash === null ? '—' : $money($item->counted_cash) }}</td>
                        <td class="num">
                            @if ($item->variance === null) —
                            @else <span class="{{ $item->variance < 0 ? 'neg' : ($item->variance > 0 ? 'pos' : 'zero') }}">{{ $item->variance > 0 ? '+' : '' }}{{ $money($item->variance) }}</span>
                            @endif
                        </td>
                        <td><span class="badge {{ $item->status }}">{{ $item->statusLabel() }}</span></td>
                        <td><a href="{{ route('finance.cash.sessions.show', $item) }}">Ouvrir</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </section>
@endsection
