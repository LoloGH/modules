@extends('pharmacie::layout')

@section('title', 'Ordonnance '.$prescription->reference)

@section('content')
    <a class="back" href="{{ route('pharmacie.prescriptions.index') }}">
        <x-pharmacie::icon name="retour" /> Retour aux ordonnances
    </a>

    <x-pharmacie::page :title="'Ordonnance '.$prescription->reference"
                       :sub="$prescription->patientName.' · '.$prescription->patientId">
        <x-slot:actions>
            @if ($prescription->isExpired()) <span class="badge warn">Validité dépassée</span> @endif
            @if ($prescription->hasAllergyWarnings()) <span class="badge danger">Allergie signalée</span> @endif
        </x-slot:actions>
    </x-pharmacie::page>

    @if ($prescription->hasAllergyWarnings())
        <x-pharmacie::card title="Allergies signalées par le dossier médical">
            <ul style="margin:0">
                @foreach ($prescription->allergyWarnings as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
            <p class="muted">
                Le logiciel signale, il ne décide pas : c'est au pharmacien de juger avant de délivrer.
            </p>
        </x-pharmacie::card>
    @endif

    <div class="cols">
        <x-pharmacie::card title="L'ordonnance">
            <dl class="facts">
                <div class="f"><dt>Prescripteur</dt><dd>{{ $prescription->prescriber ?? '-' }}</dd></div>
                <div class="f"><dt>Émise le</dt><dd>{{ $prescription->issuedOn?->format('d/m/Y') ?? '-' }}</dd></div>
                <div class="f"><dt>Valable jusqu'au</dt><dd>{{ $prescription->validUntil?->format('d/m/Y') ?? '-' }}</dd></div>
                <div class="f"><dt>Instructions</dt><dd>{{ $prescription->instructions ?? '-' }}</dd></div>
            </dl>
        </x-pharmacie::card>

        <x-pharmacie::card title="Déjà servi sur cette ordonnance">
            @if ($history->isEmpty())
                <p class="muted" style="margin:0">Rien n'a encore été délivré.</p>
            @else
                <dl class="facts">
                    @foreach ($history as $dispensation)
                        <div class="f">
                            <dt>
                                <a href="{{ route('pharmacie.dispensing.show', $dispensation) }}">{{ $dispensation->number }}</a>
                                <span class="sub">{{ $dispensation->dispensed_at?->format('d/m/Y H:i') }}</span>
                            </dt>
                            <dd>{{ $dispensation->items->sum('quantity') }} unité(s)</dd>
                        </div>
                    @endforeach
                </dl>
            @endif
        </x-pharmacie::card>
    </div>

    @can('pharmacie.dispensing.create')
        <form method="post" action="{{ route('pharmacie.dispensing.store') }}">
            @csrf
            <input type="hidden" name="patient_id" value="{{ $prescription->patientId }}">
            <input type="hidden" name="patient_name" value="{{ $prescription->patientName }}">
            <input type="hidden" name="prescription_ref" value="{{ $prescription->reference }}">

            <x-pharmacie::card title="Servir l'ordonnance" hint="Les quantités sont modifiables : ce qui manque devient un reliquat">
                @if ($location === null)
                    <p class="muted" style="margin:0">Aucun emplacement actif : impossible de délivrer.</p>
                @else
                    <label>Emplacement
                        <select name="location_id" required>
                            @foreach ($locations as $option)
                                <option value="{{ $option->id }}" @selected($option->id === $location->id)>{{ $option->name }}</option>
                            @endforeach
                        </select>
                    </label>

                    <div class="tw">
                        <table class="stack wide">
                            <thead>
                            <tr><th>Prescrit</th><th>Posologie</th><th>Produit à délivrer</th><th class="num">Disponible</th><th class="num">Quantité</th></tr>
                            </thead>
                            <tbody>
                            @foreach ($lines as $index => $row)
                                @php($line = $row['line'])
                                <tr>
                                    <td data-l="Prescrit" class="strong">
                                        {{ $line->label }}
                                        @if ($line->dosage) <span class="sub">{{ $line->dosage }}</span> @endif
                                        @unless ($line->substitutable)
                                            <span class="badge warn">Non substituable</span>
                                        @endunless
                                        @if ($row['served'] > 0)
                                            <span class="sub why">déjà servi : {{ $row['served'] }}</span>
                                        @endif
                                    </td>
                                    <td data-l="Posologie">
                                        {{ $line->posology() }}
                                        <input type="hidden" name="lines[{{ $index }}][posology]" value="{{ $line->posology() }}">
                                        <input type="hidden" name="lines[{{ $index }}][prescribed_quantity]" value="{{ $line->quantity }}">
                                    </td>
                                    <td data-l="Produit">
                                        <select name="lines[{{ $index }}][product_id]">
                                            <option value="">Ne pas délivrer cette ligne</option>
                                            @foreach ($products as $product)
                                                <option value="{{ $product->id }}" @selected($row['product'] !== null && $row['product']->id === $product->id)>
                                                    {{ $product->label() }}
                                                </option>
                                            @endforeach
                                        </select>
                                        @if ($row['batch'])
                                            <span class="sub">lot proposé : {{ $row['batch']->number }}</span>
                                        @endif
                                    </td>
                                    <td data-l="Disponible" class="num">{{ $row['available'] }}</td>
                                    <td data-l="Quantité" class="num">
                                        <input name="lines[{{ $index }}][quantity]" inputmode="numeric"
                                               value="{{ min($line->quantity, $row['available']) }}">
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="actions">
                        <button type="submit"><x-pharmacie::icon name="dispensation" /> Délivrer l'ordonnance</button>
                    </div>
                    <p class="muted">
                        Le lot proposé est celui qui périme le premier. Une ligne laissée sans produit
                        n'est pas délivrée : elle restera à servir.
                    </p>
                @endif
            </x-pharmacie::card>
        </form>
    @endcan
@endsection
