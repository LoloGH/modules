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
        @if ($lignes->isEmpty())
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
                    {{-- Une ligne par TIROIR. Des caisses groupées partagent un
                         tiroir qui s'est compté une seule fois : il n'a qu'un
                         théorique, qu'un compté, qu'un écart. Trois lignes, dont
                         deux à zéro, donnaient au contrôle trois gestes pour un
                         seul fait, et le risque d'en oublier deux. --}}
                    @foreach ($lignes as $ligne)
                        @php ($item = $ligne['session'])
                        <tr>
                            <td data-l="Session" class="mono">
                                {{ $item->number }}
                                @if ($ligne['groupe'])
                                    <span class="muted" style="display:block;font-size:.75rem">et {{ $ligne['nombre'] - 1 }} autre(s)</span>
                                @endif
                            </td>
                            <td data-l="Caisse">
                                {{ $ligne['caisses'] }}
                                @if ($ligne['groupe']) <span class="badge info">Tiroir commun</span> @endif
                            </td>
                            <td data-l="Caissier">{{ $item->cashier_name }}</td>
                            <td data-l="Clôturée le">{{ $item->closed_at?->format('d/m/Y H:i') ?? '-' }}</td>
                            <td data-l="Théorique" class="num">{{ $ligne['expected_cash'] === null ? '-' : $money($ligne['expected_cash']) }}</td>
                            <td data-l="Compté" class="num">{{ $ligne['counted_cash'] === null ? '-' : $money($ligne['counted_cash']) }}</td>
                            <td data-l="Écart" class="num">
                                @if ($ligne['variance'] === null) -
                                @else
                                    @php ($ecart = $ligne['variance'])
                                    <span class="{{ $ecart < 0 ? 'neg' : ($ecart > 0 ? 'pos' : 'zero') }}">{{ $ecart > 0 ? '+' : '' }}{{ $money($ecart) }}</span>
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
