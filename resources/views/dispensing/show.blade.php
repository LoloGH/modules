@extends('pharmacie::layout')

@section('title', 'Dispensation '.$dispensation->number)

@section('content')
    <a class="back" href="{{ route('pharmacie.dispensing.index') }}">
        <x-pharmacie::icon name="retour" /> Retour aux dispensations
    </a>

    <x-pharmacie::page :title="'Dispensation '.$dispensation->number"
                       :sub="$dispensation->patient_name ?? $dispensation->patient_id ?? 'Patient non désigné'">
        <x-slot:actions>
            <span class="badge {{ $dispensation->statusTone() }}">{{ $dispensation->statusLabel() }}</span>
            <a class="btn ghost sm" target="_blank" rel="noopener" href="{{ route('pharmacie.dispensing.print', $dispensation) }}">
                <x-pharmacie::icon name="document" /> Bon de sortie
            </a>
        </x-slot:actions>
    </x-pharmacie::page>

    @if ($dispensation->cancellation_reason)
        <x-pharmacie::card title="Dispensation annulée">
            <p class="muted" style="margin:0">
                Annulée par {{ $dispensation->cancelled_by_name }} le
                {{ $dispensation->cancelled_at?->format('d/m/Y H:i') }} : {{ $dispensation->cancellation_reason }}.
                Le stock est revenu par des écritures inverses.
            </p>
        </x-pharmacie::card>
    @endif

    <div class="cols">
        <x-pharmacie::card title="La dispensation">
            <dl class="facts">
                <div class="f"><dt>Patient</dt><dd>{{ $dispensation->patient_name ?? '—' }} <span class="mono">{{ $dispensation->patient_id }}</span></dd></div>
                <div class="f"><dt>Origine</dt><dd>{{ $dispensation->sourceLabel() }}</dd></div>
                <div class="f"><dt>Ordonnance</dt><dd class="mono">{{ $dispensation->prescription_ref ?? '—' }}</dd></div>
                <div class="f"><dt>Emplacement</dt><dd>{{ $dispensation->location?->name }}</dd></div>
                <div class="f"><dt>Délivrée par</dt><dd>{{ $dispensation->dispensed_by_name }}</dd></div>
                <div class="f"><dt>Le</dt><dd>{{ $dispensation->dispensed_at?->format('d/m/Y H:i') }}</dd></div>
                <div class="f total"><dt>Total</dt><dd>{{ $money($dispensation->total) }}</dd></div>
            </dl>
        </x-pharmacie::card>

        <x-pharmacie::card title="Reste à délivrer">
            @if ($dispensation->outstanding > 0)
                <p><strong>{{ $dispensation->outstanding }}</strong> unité(s) n'ont pas pu être délivrées.</p>
                <ul class="muted">
                    @foreach ($dispensation->items as $item)
                        @if ($item->outstanding() > 0)
                            <li>{{ $item->label }} : {{ $item->outstanding() }} sur {{ $item->prescribed_quantity }}</li>
                        @endif
                    @endforeach
                </ul>
            @else
                <p class="muted" style="margin:0">Tout ce qui était prescrit a été délivré.</p>
            @endif

            @can('pharmacie.dispensing.cancel')
                @unless ($dispensation->isCancelled())
                    <form method="post" action="{{ route('pharmacie.dispensing.cancel', $dispensation) }}" class="inline" style="margin-top:1rem">
                        @csrf
                        <input name="reason" placeholder="Motif de l'annulation" required>
                        <button type="submit" class="danger sm">Annuler la dispensation</button>
                    </form>
                @endunless
            @endcan
        </x-pharmacie::card>
    </div>

    <x-pharmacie::card title="Ce qui a été délivré" hint="{{ $dispensation->items->count() }} ligne(s)" flush>
        <div class="tw">
            <table class="stack wide">
                <thead>
                <tr><th>Produit</th><th>Posologie</th><th class="num">Prescrit</th><th class="num">Délivré</th><th>Lots</th><th class="num">Prix</th><th class="num">Montant</th></tr>
                </thead>
                <tbody>
                @foreach ($dispensation->items as $item)
                    <tr>
                        <td data-l="Produit" class="strong">
                            {{ $item->label }}
                            @if ($item->isSubstituted())
                                <span class="badge info">Substitution</span>
                                <span class="sub why">{{ $item->substitution_reason }}</span>
                            @endif
                            @if ($item->comment) <span class="sub">{{ $item->comment }}</span> @endif
                        </td>
                        <td data-l="Posologie">{{ $item->posology ?? '—' }}</td>
                        <td data-l="Prescrit" class="num">{{ $item->prescribed_quantity }}</td>
                        <td data-l="Délivré" class="num strong">
                            {{ $item->quantity }}
                            @if ($item->outstanding() > 0)
                                <span class="sub">reste {{ $item->outstanding() }}</span>
                            @endif
                        </td>
                        <td data-l="Lots">
                            @foreach ($item->batches as $served)
                                <div>
                                    <span class="mono">{{ $served->batch?->number }}</span>
                                    × {{ $served->quantity }}
                                    @if ($served->batch?->expires_on)
                                        <span class="sub">périme le {{ $served->batch->expires_on->format('d/m/Y') }}</span>
                                    @endif
                                    @if ($served->overrode_fefo)
                                        <span class="badge warn">Hors FEFO</span>
                                        <span class="sub why">{{ $served->override_reason }}</span>
                                    @endif
                                </div>
                            @endforeach
                        </td>
                        <td data-l="Prix" class="num">{{ $money($item->unit_price) }}</td>
                        <td data-l="Montant" class="num">{{ $money($item->amount) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </x-pharmacie::card>
@endsection
