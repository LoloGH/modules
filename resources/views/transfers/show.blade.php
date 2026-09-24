@extends('pharmacie::layout')

@section('title', 'Transfert '.$transfer->number)

@section('content')
    <a class="back" href="{{ route('pharmacie.transfers.index') }}">
        <x-pharmacie::icon name="retour" /> Retour aux transferts
    </a>

    <x-pharmacie::page :title="'Transfert '.$transfer->number"
                       :sub="$transfer->from?->name.' → '.$transfer->to?->name">
        <x-slot:actions>
            <span class="badge {{ $transfer->statusTone() }}">{{ $transfer->statusLabel() }}</span>
        </x-slot:actions>
    </x-pharmacie::page>

    @if ($transfer->decision_reason)
        <x-pharmacie::card title="Décision">
            <p class="muted" style="margin:0">{{ $transfer->decision_reason }}</p>
        </x-pharmacie::card>
    @endif

    <x-pharmacie::card title="Le transfert">
        <dl class="facts">
            <div class="f"><dt>Motif</dt><dd>{{ $transfer->reason ?? '—' }}</dd></div>
            <div class="f"><dt>Demandé par</dt><dd>{{ $transfer->requested_by_name ?? '—' }}</dd></div>
            <div class="f"><dt>Validé par</dt><dd>{{ $transfer->approved_by_name ?? '—' }}</dd></div>
            <div class="f"><dt>Parti le</dt><dd>{{ $transfer->sent_at?->format('d/m/Y H:i') ?? '—' }}</dd></div>
            <div class="f"><dt>Reçu le</dt><dd>{{ $transfer->received_at?->format('d/m/Y H:i') ?? '—' }}</dd></div>
        </dl>

        @if ($transfer->isInTransit())
            <p class="muted">
                Les unités sont sorties de {{ $transfer->from?->name }} et ne sont pas encore
                entrées à {{ $transfer->to?->name }} : elles sont en transit, et à personne.
            </p>
        @endif
    </x-pharmacie::card>

    @can('pharmacie.stock.adjust')
        @if ($transfer->status === 'requested')
            <x-pharmacie::card title="Valider la demande">
                <form method="post" action="{{ route('pharmacie.transfers.decide', $transfer) }}" class="inline">
                    @csrf
                    <input type="hidden" name="decision" value="approve">
                    <button type="submit">Valider</button>
                </form>
                <form method="post" action="{{ route('pharmacie.transfers.decide', $transfer) }}" class="inline" style="margin-top:.75rem">
                    @csrf
                    <input type="hidden" name="decision" value="refuse">
                    <input name="reason" placeholder="Motif du refus" required>
                    <button type="submit" class="danger sm">Refuser</button>
                </form>
            </x-pharmacie::card>
        @elseif ($transfer->status === 'approved')
            <x-pharmacie::card title="Envoyer">
                <form method="post" action="{{ route('pharmacie.transfers.send', $transfer) }}" class="inline">
                    @csrf
                    <button type="submit"><x-pharmacie::icon name="fleche" /> Sortir le stock et envoyer</button>
                    <span class="muted">Les lots sont choisis en FEFO, comme au comptoir.</span>
                </form>
            </x-pharmacie::card>
        @elseif ($transfer->isInTransit())
            <x-pharmacie::card title="Recevoir" hint="Un écart se justifie">
                <form method="post" action="{{ route('pharmacie.transfers.receive', $transfer) }}">
                    @csrf
                    <div class="tw">
                        <table class="stack wide">
                            <thead><tr><th>Produit</th><th>Lot</th><th class="num">Parti</th><th class="num">Reçu</th><th>Motif de l'écart</th></tr></thead>
                            <tbody>
                            @foreach ($transfer->lines as $line)
                                <tr>
                                    <td data-l="Produit" class="strong">{{ $line->label }}</td>
                                    <td data-l="Lot" class="mono">{{ $line->batch?->number ?? '—' }}</td>
                                    <td data-l="Parti" class="num">{{ $line->quantity }}</td>
                                    <td data-l="Reçu" class="num">
                                        <input name="lines[{{ $line->id }}][quantity]" inputmode="numeric" value="{{ $line->quantity }}">
                                    </td>
                                    <td data-l="Écart"><input name="lines[{{ $line->id }}][gap_reason]" placeholder="si moins que parti"></td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="actions">
                        <button type="submit"><x-pharmacie::icon name="reception" /> Recevoir</button>
                    </div>
                </form>
            </x-pharmacie::card>
        @endif
    @endcan

    <x-pharmacie::card title="Lignes" hint="{{ $transfer->lines->count() }} ligne(s)" flush>
        <div class="tw">
            <table class="stack wide">
                <thead><tr><th>Produit</th><th>Lot</th><th class="num">Demandé</th><th class="num">Reçu</th><th class="num">Écart</th><th>Motif</th></tr></thead>
                <tbody>
                @foreach ($transfer->lines as $line)
                    <tr>
                        <td data-l="Produit" class="strong">{{ $line->label }}</td>
                        <td data-l="Lot" class="mono">{{ $line->batch?->number ?? '—' }}</td>
                        <td data-l="Demandé" class="num">{{ $line->quantity }}</td>
                        <td data-l="Reçu" class="num">{{ $line->received_quantity }}</td>
                        <td data-l="Écart" class="num strong">{{ $line->gap() ?: '—' }}</td>
                        <td data-l="Motif">{{ $line->gap_reason ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </x-pharmacie::card>
@endsection
