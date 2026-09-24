@extends('pharmacie::layout')

@section('title', 'Rappel '.$recall->number)

@section('content')
    <a class="back" href="{{ route('pharmacie.vigilance.recalls.index') }}">
        <x-pharmacie::icon name="retour" /> Retour aux rappels
    </a>

    <x-pharmacie::page :title="'Rappel '.$recall->number"
                       :sub="($recall->batch?->product?->label() ?? '').' · lot '.($recall->batch?->number ?? '')">
        <x-slot:actions>
            <span class="badge {{ $recall->statusTone() }}">{{ $recall->statusLabel() }}</span>
        </x-slot:actions>
    </x-pharmacie::page>

    <x-pharmacie::card title="Ce qui est rappelé">
        <dl class="facts">
            <div class="f"><dt>Origine</dt><dd>{{ $recall->originLabel() }}</dd></div>
            <div class="f"><dt>Référence</dt><dd class="mono">{{ $recall->reference ?? '—' }}</dd></div>
            <div class="f"><dt>Portée</dt><dd>{{ $recall->levelLabel() }}</dd></div>
            <div class="f"><dt>Unités bloquées</dt><dd>{{ $recall->quantity_blocked }}</dd></div>
            <div class="f"><dt>Ouvert par</dt><dd>{{ $recall->opened_by_name ?? '—' }}</dd></div>
            <div class="f"><dt>Ouvert le</dt><dd>{{ $recall->opened_at?->format('d/m/Y H:i') ?? '—' }}</dd></div>
        </dl>
        <p class="muted">{{ $recall->reason }}</p>
        @if ($recall->closing_note)
            <p class="muted">Clos le {{ $recall->closed_at?->format('d/m/Y') }} par
                {{ $recall->closed_by_name }} : {{ $recall->closing_note }}</p>
        @endif
    </x-pharmacie::card>

    <x-pharmacie::card title="Patients ayant reçu ce lot"
        hint="{{ $recall->patients->count() }} dispensation(s), {{ $recall->remainingToContact() }} à joindre" flush>
        @if ($recall->patients->isEmpty())
            <div class="bd">
                <x-pharmacie::empty title="Aucun patient servi" icon="check">
                    Ce lot n'était pas encore sorti : le retirer du stock suffit.
                </x-pharmacie::empty>
            </div>
        @else
            <div class="tw">
                <table class="stack wide">
                    <thead><tr>
                        <th>Patient</th><th>Produit</th><th class="num">Quantité</th>
                        <th>Délivré le</th><th>Dispensation</th><th>Suivi</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($recall->patients as $line)
                        <tr>
                            <td data-l="Patient" class="strong">
                                {{ $line->label() }}
                                @if ($line->patient_id) <span class="sub mono">{{ $line->patient_id }}</span> @endif
                            </td>
                            <td data-l="Produit">{{ $line->product_label }}</td>
                            <td data-l="Quantité" class="num">{{ $line->quantity }}</td>
                            <td data-l="Délivré le">{{ $line->dispensed_at?->format('d/m/Y') ?? '—' }}</td>
                            <td data-l="Dispensation" class="mono">
                                @if ($line->dispensation)
                                    <a href="{{ route('pharmacie.dispensing.show', $line->dispensation) }}">{{ $line->dispensation->number }}</a>
                                @else
                                    —
                                @endif
                            </td>
                            <td data-l="Suivi">
                                @if ($line->contacted)
                                    <span class="badge ok">Joint</span>
                                    <span class="sub">{{ $line->contacted_at?->format('d/m/Y') }} ·
                                        {{ $line->contacted_by_name }} — {{ $line->contact_note }}</span>
                                @elseif ($recall->isOpen())
                                    @can('pharmacie.vigilance.manage')
                                        <form method="post" action="{{ route('pharmacie.vigilance.recalls.contact', $line) }}" class="inline">
                                            @csrf
                                            <input name="note" placeholder="ce que le patient a répondu" required>
                                            <button type="submit" class="sm">Noter l'appel</button>
                                        </form>
                                    @else
                                        <span class="badge warn">À joindre</span>
                                    @endcan
                                @else
                                    <span class="badge off">Non joint</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-pharmacie::card>

    @if ($recall->isOpen())
        @can('pharmacie.vigilance.manage')
            <x-pharmacie::card title="Clore le rappel"
                hint="Un rappel qui vise les patients ne se clôt pas avant de les avoir joints">
                <form method="post" action="{{ route('pharmacie.vigilance.recalls.close', $recall) }}" class="inline">
                    @csrf
                    <input name="note" placeholder="Ce qu'il est advenu du lot (détruit, retourné au fournisseur…)" required>
                    <button type="submit"><x-pharmacie::icon name="check" /> Clore</button>
                </form>
            </x-pharmacie::card>
        @endcan
    @endif
@endsection
