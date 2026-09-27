@extends('pharmacie::layout')

@section('title', 'Préparations')

@section('content')
    <x-pharmacie::page title="Préparations"
        sub="Ce qui attend entre le comptoir et la caisse. Rien n'est encore sorti du stock." />

    <x-pharmacie::card title="En attente" hint="{{ $preparations->total() }} préparation(s)" flush>
        @if ($preparations->isEmpty())
            <div class="bd">
                <x-pharmacie::empty title="Aucune préparation en attente" icon="check">
                    Tout ce qui a été préparé a été délivré ou abandonné.
                </x-pharmacie::empty>
            </div>
        @else
            <div class="tw">
                <table class="stack wide">
                    <thead><tr>
                        <th>N°</th><th>Patient</th><th class="num">Lignes</th><th class="num">Montant</th>
                        <th>Caisse</th><th>Préparée</th><th></th>
                    </tr></thead>
                    <tbody>
                    @foreach ($preparations as $preparation)
                        @php
                            $status = $statuses[$preparation->id] ?? null;
                            $attente = $preparation->prepared_at?->diffInHours(now());
                            $oubliee = $stale > 0 && $attente !== null && $attente >= $stale
                                && ($status === null || ! $status->settledForPatient());
                        @endphp
                        <tr>
                            <td data-l="N°" class="mono strong">
                                {{ $preparation->number }}
                                @if ($preparation->reservations->isNotEmpty())
                                    <span class="sub">{{ $preparation->reservations->sum('quantity') }} unité(s) réservées</span>
                                @endif
                            </td>
                            <td data-l="Patient">
                                {{ $preparation->patient_name ?? $preparation->patient_id ?? 'Patient non désigné' }}
                                @if ($preparation->coverage_insurer)
                                    <span class="sub">{{ $preparation->coverage_insurer }}@if ($preparation->coverage_rate) · {{ $preparation->coverage_rate }} % @endif</span>
                                @endif
                            </td>
                            <td data-l="Lignes" class="num">{{ $preparation->items->count() }}</td>
                            <td data-l="Montant" class="num">{{ $money((int) $preparation->total) }}</td>
                            <td data-l="Caisse">
                                @if ($preparation->billing_reference === null)
                                    <span class="badge warn">Pas de pièce</span>
                                @elseif ($status === null)
                                    <span class="badge off">Caisse injoignable</span>
                                @elseif ($status->cancelled)
                                    <span class="badge danger">Pièce annulée</span>
                                @elseif ($status->settledForPatient())
                                    <span class="badge ok">Réglée</span>
                                @else
                                    <span class="badge warn">Reste {{ $money($status->patientDue) }}</span>
                                @endif
                            </td>
                            <td data-l="Préparée">
                                {{ $preparation->prepared_at?->format('d/m/Y H:i') ?? '-' }}
                                @if ($oubliee)
                                    <span class="sub">en attente depuis {{ $attente }} h</span>
                                @endif
                            </td>
                            <td data-l="" class="acts">
                                <a class="btn ghost sm" href="{{ route('pharmacie.preparations.show', $preparation) }}">Ouvrir</a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-pharmacie::card>

    @if ($abandoned->isNotEmpty())
        <x-pharmacie::card title="Abandonnées récemment"
            hint="Le stock réservé a été rendu" flush>
            <div class="tw">
                <table class="stack wide">
                    <thead><tr><th>N°</th><th>Patient</th><th>Motif</th><th>Le</th></tr></thead>
                    <tbody>
                    @foreach ($abandoned as $preparation)
                        <tr>
                            <td data-l="N°" class="mono">{{ $preparation->number }}</td>
                            <td data-l="Patient">{{ $preparation->patient_name ?? $preparation->patient_id ?? 'Patient non désigné' }}</td>
                            <td data-l="Motif">{{ $preparation->cancellation_reason }}</td>
                            <td data-l="Le">{{ $preparation->cancelled_at?->format('d/m/Y H:i') }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </x-pharmacie::card>
    @endif
@endsection
