@extends('finance::layout')

@section('title', 'Sessions à valider')

@section('content')
    <x-finance::page title="Sessions à valider" sub="Contrôler les clôtures de caisse et les écarts." />

    <nav class="tabs">
        @foreach (['closed' => 'À valider', 'validated' => 'Validées', 'open' => 'Ouvertes', 'all' => 'Toutes'] as $key => $label)
            <a href="{{ route('finance.review.index', ['status' => $key]) }}" class="{{ $status === $key ? 'on' : '' }}">{{ $label }}</a>
        @endforeach
    </nav>

    <x-finance::card flush>
        @if ($sessions->isEmpty())
            <div class="bd">
                <x-finance::empty title="Aucune session dans cette liste" icon="controle">
                    Les clôtures en attente de contrôle apparaîtront ici.
                </x-finance::empty>
            </div>
        @else
            <div class="tw">
                <table class="stack wide">
                    <thead>
                    <tr>
                        <th>Session</th><th>Caisse</th><th>Caissier</th><th>Clôturée le</th>
                        <th class="num">Théorique</th><th class="num">Compté</th><th class="num">Écart</th>
                        <th>Statut</th><th></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($sessions as $item)
                        <tr>
                            <td data-l="Session" class="mono">{{ $item->number }}</td>
                            <td data-l="Caisse">{{ $item->register->name }}</td>
                            <td data-l="Caissier">{{ $item->cashier_name }}</td>
                            <td data-l="Clôturée le">{{ $item->closed_at?->format('d/m/Y H:i') ?? '—' }}</td>
                            <td data-l="Théorique" class="num">{{ $item->expected_cash === null ? '—' : $money($item->expected_cash) }}</td>
                            <td data-l="Compté" class="num">{{ $item->counted_cash === null ? '—' : $money($item->counted_cash) }}</td>
                            <td data-l="Écart" class="num">
                                @if ($item->variance === null) —
                                @else <span class="{{ $item->variance < 0 ? 'neg' : ($item->variance > 0 ? 'pos' : 'zero') }}">{{ $item->variance > 0 ? '+' : '' }}{{ $money($item->variance) }}</span>
                                @endif
                            </td>
                            <td data-l="Statut"><span class="badge {{ $item->status }}">{{ $item->statusLabel() }}</span></td>
                            <td data-l="" class="acts">
                                <a class="btn ghost sm" href="{{ route('finance.cash.sessions.show', $item) }}">Ouvrir</a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-finance::card>
@endsection
