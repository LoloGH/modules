@extends('pharmacie::layout')

@section('title', 'Préparation '.$preparation->number)

@section('content')
    <a class="back" href="{{ route('pharmacie.preparations.index') }}">
        <x-pharmacie::icon name="retour" /> Retour aux préparations
    </a>

    <x-pharmacie::page :title="'Préparation '.$preparation->number"
                       :sub="$preparation->patient_name ?? $preparation->patient_id ?? 'Patient non désigné'">
        <x-slot:actions>
            @if ($status === null)
                <span class="badge off">Caisse injoignable</span>
            @elseif ($status->cancelled)
                <span class="badge danger">Pièce annulée</span>
            @elseif ($status->settledForPatient())
                <span class="badge ok">Part patient réglée</span>
            @else
                <span class="badge warn">Reste {{ $money($status->patientDue) }}</span>
            @endif
        </x-slot:actions>
    </x-pharmacie::page>

    <x-pharmacie::card title="Ce qui a été préparé">
        <dl class="facts">
            <div class="f"><dt>Montant</dt><dd>{{ $money((int) $preparation->total) }}</dd></div>
            <div class="f"><dt>Pièce en caisse</dt><dd class="mono">{{ $preparation->billing_reference ?? '-' }}</dd></div>
            <div class="f"><dt>Préparée par</dt><dd>{{ $preparation->prepared_by_name ?? '-' }}</dd></div>
            <div class="f"><dt>Préparée le</dt><dd>{{ $preparation->prepared_at?->format('d/m/Y H:i') ?? '-' }}</dd></div>
            <div class="f"><dt>Emplacement</dt><dd>{{ $preparation->location?->name ?? '-' }}</dd></div>
            <div class="f"><dt>Ordonnance</dt><dd class="mono">{{ $preparation->prescription_ref ?? '-' }}</dd></div>
        </dl>

        @if ($preparation->coverage_insurer)
            @php
                // Une seule phrase, montee ici : enchainer les conditions dans
                // le texte rendait la ligne illisible, et Blade s'y perdait.
                $priseEnCharge = array_filter([
                    $preparation->coverage_insurer,
                    $preparation->coverage_rate ? $preparation->coverage_rate.' %' : null,
                    $preparation->coverage_reference ? 'accord '.$preparation->coverage_reference : null,
                ]);
            @endphp

            <p class="muted">
                Prise en charge : <strong>{{ implode(', ', $priseEnCharge) }}</strong>.
                Les unités sont mises de côté jusqu'à la délivrance.
            </p>
        @endif

        @if ($status !== null && $status->isCovered())
            <p class="muted">
                La caisse porte {{ $money($status->coveredShare) }} au compte de
                {{ $status->insurerName ?? 'l\'organisme' }}. Ce qu'il n'a pas encore versé ne
                retient pas le patient : c'est une créance, elle se suit en caisse.
            </p>
        @endif
    </x-pharmacie::card>

    @if ($preparation->billing_reference === null)
        <x-pharmacie::card title="La facture n'est pas partie"
            hint="La caisse n'a pas répondu au moment de la préparation">
            <p class="muted">
                Rien n'est perdu : la préparation est écrite, et le stock n'a pas bougé.
                Renvoyez la facture pour que le patient puisse régler.
            </p>
            <form method="post" action="{{ route('pharmacie.preparations.resend', $preparation) }}" class="inline">
                @csrf
                <button type="submit"><x-pharmacie::icon name="fleche" /> Renvoyer à la caisse</button>
            </form>
        </x-pharmacie::card>
    @endif

    <form method="post" action="{{ route('pharmacie.preparations.deliver', $preparation) }}">
        @csrf
        <x-pharmacie::card title="À délivrer"
            hint="Le lot se choisit maintenant : le stock a pu bouger depuis la préparation" flush>
            <div class="tw">
                <table class="stack wide">
                    <thead><tr>
                        <th>Produit</th><th class="num">Demandé</th><th class="num">Disponible</th>
                        <th>Lot</th><th>Motif si autre lot</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($preparation->items as $item)
                        @php($dispo = $available[$item->id] ?? ['total' => 0, 'batches' => [], 'location' => null])
                        <tr>
                            <td data-l="Produit" class="strong">
                                {{ $item->label }}
                                @if ($item->posology) <span class="sub">{{ $item->posology }}</span> @endif
                                @if ($item->location_id)
                                    {{-- Cette ligne ne vient pas du comptoir : on dit ou aller la
                                         chercher, et c'est de la que le stock sortira. --}}
                                    <span class="sub">à prendre à {{ $dispo['location']?->name ?? $item->location?->name }}</span>
                                @endif
                            </td>
                            <td data-l="Demandé" class="num">{{ $item->prescribed_quantity }}</td>
                            <td data-l="Disponible" class="num {{ $dispo['total'] < $item->prescribed_quantity ? 'strong' : '' }}">
                                {{ $dispo['total'] }}
                                @if ($dispo['total'] < $item->prescribed_quantity)
                                    <span class="sub">il manquera {{ $item->prescribed_quantity - $dispo['total'] }}</span>
                                @endif
                            </td>
                            <td data-l="Lot">
                                <select name="lines[{{ $item->id }}][batch_id]">
                                    <option value="">Le lot qui périme le premier</option>
                                    @foreach ($dispo['batches'] as $row)
                                        <option value="{{ $row['batch']->id }}">
                                            {{ $row['batch']->number }}
                                            @if ($row['batch']->expires_on) (périme le {{ $row['batch']->expires_on->format('d/m/Y') }}) @endif
                                            · {{ $row['available'] }} dispo
                                        </option>
                                    @endforeach
                                </select>
                            </td>
                            <td data-l="Motif si autre lot">
                                <input name="lines[{{ $item->id }}][override_reason]"
                                       placeholder="obligatoire si le lot choisi périme après">
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <div class="bd">
                @if ($status !== null && $status->settledForPatient() && ! $status->cancelled)
                    <div class="actions">
                        <button type="submit"><x-pharmacie::icon name="dispensation" /> Délivrer au patient</button>
                    </div>
                    <p class="muted">
                        C'est à cet instant que le stock sort. Ce qui manque devient un reliquat
                        visible, et le dossier médical apprend ce qui a été servi.
                    </p>
                @else
                    <p class="muted">
                        La délivrance attend que la part du patient soit soldée en caisse.
                        @if ($status === null && $preparation->billing_reference !== null)
                            La caisse ne répond pas : impossible de savoir où en est la pièce,
                            et servir sans le savoir serait pire que faire patienter.
                        @endif
                    </p>
                @endif
            </div>
        </x-pharmacie::card>
    </form>

    <x-pharmacie::card title="Abandonner" hint="Ce qui est réservé revient aux autres patients">
        <form method="post" action="{{ route('pharmacie.preparations.abandon', $preparation) }}" class="inline">
            @csrf
            <input name="reason" placeholder="Motif : patient non revenu, erreur de saisie…" required>
            <button type="submit" class="danger sm">Abandonner la préparation</button>
        </form>
    </x-pharmacie::card>
@endsection
