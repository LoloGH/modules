@extends('pharmacie::layout')

@section('title', 'Ordonnances')

@section('content')
    <x-pharmacie::page title="Ordonnances à servir"
        sub="Ce que les prescripteurs ont écrit, tel que le dossier médical le fournit." />

    @unless ($connected)
        <x-pharmacie::card title="Dossier médical non branché">
            <p class="muted" style="margin:0">
                L'application hôte ne fournit pas encore d'ordonnances. La pharmacie reste
                utilisable : on sert au comptoir, sans ordonnance électronique. Branchez une
                implémentation de <code>Contracts\PrescriptionProvider</code> pour voir ici ce
                que les prescripteurs ont écrit.
            </p>
        </x-pharmacie::card>
    @endunless

    <x-pharmacie::card title="À préparer" hint="{{ count($prescriptions) }} ordonnance(s)" flush>
        @if ($prescriptions === [])
            <div class="bd">
                <x-pharmacie::empty title="Aucune ordonnance en attente" icon="check">
                    Les ordonnances validées par les prescripteurs apparaîtront ici.
                </x-pharmacie::empty>
            </div>
        @else
            <div class="tw">
                <table class="stack wide">
                    <thead>
                    <tr><th>Ordonnance</th><th>Patient</th><th>Prescripteur</th><th>Émise le</th><th class="num">Lignes</th><th>Alertes</th><th></th></tr>
                    </thead>
                    <tbody>
                    @foreach ($prescriptions as $prescription)
                        <tr>
                            <td data-l="Ordonnance" class="mono strong">
                                {{ $prescription->reference }}
                                @if (($served[$prescription->reference] ?? 0) > 0)
                                    <span class="badge warn">Déjà servie en partie</span>
                                @endif
                            </td>
                            <td data-l="Patient">
                                {{ $prescription->patientName }}
                                <span class="sub mono">{{ $prescription->patientId }}</span>
                            </td>
                            <td data-l="Prescripteur">{{ $prescription->prescriber ?? '-' }}</td>
                            <td data-l="Émise le">
                                {{ $prescription->issuedOn?->format('d/m/Y') ?? '-' }}
                                @if ($prescription->isExpired())
                                    <span class="sub why">validité dépassée</span>
                                @endif
                            </td>
                            <td data-l="Lignes" class="num">{{ count($prescription->lines) }}</td>
                            <td data-l="Alertes">
                                @if ($prescription->hasAllergyWarnings())
                                    <span class="badge danger">Allergie signalée</span>
                                @else
                                    <span class="muted">-</span>
                                @endif
                            </td>
                            <td data-l="" class="acts">
                                <a class="btn ghost sm" href="{{ route('pharmacie.prescriptions.show', $prescription->reference) }}">Préparer</a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-pharmacie::card>
@endsection
