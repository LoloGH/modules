@extends('pharmacie::layout')

@section('title', 'Délivrer')

@section('content')
    <x-pharmacie::page title="Délivrer"
        sub="Le lot proposé est celui qui périme le premier. En servir un autre est possible, avec un motif." />

    @if ($location === null)
        <x-pharmacie::card>
            <x-pharmacie::empty title="Aucun emplacement" icon="reception">
                Créez d'abord un emplacement : on ne délivre pas de nulle part.
            </x-pharmacie::empty>
        </x-pharmacie::card>
    @else
        {{-- Le stock affiche est celui de cet emplacement : en changer change
             ce qui est disponible, donc l'ecran se recharge. --}}
        <form method="get" action="{{ route('pharmacie.dispensing.create') }}" class="switch">
            @if ($queued)
                <input type="hidden" name="file" value="{{ $queued['ref_file'] }}">
                <input type="hidden" name="patient" value="{{ $queued['ref'] }}">
            @endif
            @if ($prescription)
                <input type="hidden" name="ordonnance" value="{{ $prescription->reference }}">
            @endif
            <span class="lbl">On sert depuis :</span>
            <select name="emplacement" onchange="this.form.submit()" style="width:auto">
                @foreach ($locations as $option)
                    <option value="{{ $option->id }}" @selected($option->id === $location->id)>{{ $option->name }}</option>
                @endforeach
            </select>
            <noscript><button type="submit" class="btn sm">Changer</button></noscript>
        </form>

        <form method="post" action="{{ route('pharmacie.dispensing.store') }}">
            @csrf
            <input type="hidden" name="location_id" value="{{ $location->id }}">

            <x-pharmacie::card title="Le patient">
                <div class="row">
                    <label>Identifiant du patient
                        <input name="patient_id" value="{{ old('patient_id', $queued['patient_id'] ?? '') }}" placeholder="PAT-000123">
                    </label>
                    <label>Nom du patient
                        <input name="patient_name" value="{{ old('patient_name', $queued['patient_name'] ?? '') }}">
                    </label>
                    <label>Ordonnance
                        <input name="prescription_ref" value="{{ old('prescription_ref', $queued['prescription'] ?? '') }}" placeholder="ORD-2026-000097">
                    </label>
                </div>
                @if ($queued)
                    <input type="hidden" name="queue_ref" value="{{ $queued['ref'] }}">
                    <p class="muted">Patient appelé depuis la file : son identité et son ordonnance sont reprises telles quelles.</p>
                @endif
                @if ($prescription)
                    <p class="muted">
                        Ordonnance <span class="mono">{{ $prescription->reference }}</span> reprise du dossier médical :
                        {{ count($prescription->lines) }} ligne(s) prescrite(s)@if ($prescription->prescriber), {{ $prescription->prescriber }}@endif.
                        Les quantités restent modifiables.
                    </p>
                    @if ($prescription->hasAllergyWarnings())
                        <p class="flag out">
                            Allergie signalée par le dossier médical :
                            {{ implode(', ', $prescription->allergyWarnings) }}.
                        </p>
                    @endif
                    @if ($prescription->isExpired())
                        <p class="flag short">
                            Validité dépassée le {{ $prescription->validUntil?->format('d/m/Y') }} : au pharmacien de juger.
                        </p>
                    @endif
                @endif
            </x-pharmacie::card>

            <x-pharmacie::card title="Ce qui est délivré"
                hint="{{ $prescription ? count($prescription->lines).' ligne(s) prescrite(s)' : 'Trois lignes à la fois' }}">
                @if ($products->isEmpty())
                    <p class="muted" style="margin:0">Aucun produit actif au catalogue.</p>
                @else
                    @foreach ($rows as $i => $row)
                        @php
                            // Rouge quand la ligne ne peut pas etre servie : aucun produit
                            // au catalogue, ou rien en stock. Orange quand il en manquera.
                            // Ce qui est ailleurs n'est pas en rupture : on va le
                            // chercher. L'ecran ne crie que sur ce qui manque vraiment.
                            $ailleurs = $row['product_id'] ? ($elsewhere[$row['product_id']] ?? []) : [];

                            $etat = match (true) {
                                $row['label'] === null => null,
                                ! $row['matched'], $row['available'] === 0 => 'out',
                                $row['prescribed'] !== null && $row['available'] < $row['prescribed'] => 'short',
                                default => null,
                            };

                            // Les lots proposes : ceux du produit quand on le connait,
                            // sinon tous, car le pharmacien n'a pas encore choisi.
                            $lots = $row['batches'] ?? collect($suggestions)->flatMap(fn (array $info): array => $info['batches'])->all();
                        @endphp

                        <div class="line {{ $etat ?? ($row['from'] ? 'ailleurs' : '') }}">
                            @if ($row['label'])
                                <p class="pres">
                                    <span class="strong">{{ $row['label'] }}</span>
                                    <span class="sub">
                                        {{ $row['prescribed'] === null ? 'quantité à fixer' : $row['prescribed'].' prescrit(s)' }}
                                    </span>
                                    @if ($row['served'] > 0)
                                        <span class="sub">déjà servi : {{ $row['served'] }}</span>
                                    @endif
                                    @unless ($row['substitutable'])
                                        <span class="badge warn">Non substituable</span>
                                    @endunless
                                    @foreach ($coverages[$row['product_id']] ?? [] as $prise)
                                        <span class="badge info">{{ $prise['name'] }} {{ $prise['rate'] }} %</span>
                                    @endforeach
                                </p>
                                @if ($etat === 'out')
                                    <p class="flag out">
                                        {{ $row['matched'] ? 'Rien en stock, ici comme ailleurs' : 'Aucun produit du catalogue ne correspond' }}.
                                        Laissée telle quelle, cette ligne ne sera pas délivrée.
                                    </p>
                                @elseif ($row['from'])
                                    <p class="flag ailleurs">
                                        Rien au comptoir, mais {{ $row['available'] }} à {{ $row['from']->name }} :
                                        cette ligne y sera prise. Le stock sortira de là, et le dira.
                                    </p>
                                @elseif ($etat === 'short')
                                    <p class="flag short">
                                        {{ $row['available'] }} en stock pour {{ $row['prescribed'] }} prescrit(s) :
                                        il manquera {{ $row['prescribed'] - $row['available'] }} unité(s).
                                    </p>
                                @endif
                            @endif

                            <div class="row">
                                <label>Produit {{ $i + 1 }}
                                    <select name="lines[{{ $i }}][product_id]">
                                        <option value="">{{ $row['label'] ? 'Ne pas délivrer cette ligne' : 'Aucun' }}</option>
                                        @foreach ($products as $product)
                                            @php($info = $suggestions[$product->id] ?? ['available' => 0, 'batch' => null])
                                            <option value="{{ $product->id }}" @selected($row['product_id'] === $product->id)>
                                                {{ $product->label() }} · {{ $info['available'] }} disponible(s)@if ($info['batch']) · lot {{ $info['batch']->number }}@endif
                                            </option>
                                        @endforeach
                                    </select>
                                </label>
                                <label>Prescrit
                                    <input name="lines[{{ $i }}][prescribed_quantity]" inputmode="numeric"
                                           value="{{ $row['prescribed'] }}" placeholder="ex. 20">
                                </label>
                                <label>Délivré
                                    <input name="lines[{{ $i }}][quantity]" inputmode="numeric" value="{{ $row['quantity'] }}">
                                </label>
                                <label>Posologie
                                    <input name="lines[{{ $i }}][posology]" value="{{ $row['posology'] }}"
                                           placeholder="1 gélule matin et soir">
                                </label>
                            </div>
                            @if ($ailleurs !== [])
                                <div class="row">
                                    <label>Prendre depuis
                                        <select name="lines[{{ $i }}][location_id]">
                                            <option value="">
                                                {{ $location->name }}@if ($row['from'] === null && $row['available'] !== null) · {{ $row['available'] }} disponible(s)@endif
                                            </option>
                                            @foreach ($ailleurs as $autre)
                                                <option value="{{ $autre['location']->id }}"
                                                        @selected($row['from'] && $row['from']->id === $autre['location']->id)>
                                                    {{ $autre['location']->name }} · {{ $autre['available'] }} disponible(s)
                                                </option>
                                            @endforeach
                                        </select>
                                        <span class="help">
                                            Le comptoir ne tient pas tout. Ce qui est pris ailleurs en sort vraiment :
                                            le grand livre l'écrit au bon emplacement.
                                        </span>
                                    </label>
                                </div>
                            @endif

                            <div class="row">
                                <label>Lot (facultatif, FEFO par défaut)
                                    <select name="lines[{{ $i }}][batch_id]">
                                        <option value="">Le lot qui périme le premier</option>
                                        @foreach ($row['from'] ? [] : $lots as $lot)
                                            <option value="{{ $lot['batch']->id }}">
                                                {{ $lot['batch']->product?->code }} · lot {{ $lot['batch']->number }}
                                                @if ($lot['batch']->expires_on) (périme le {{ $lot['batch']->expires_on->format('d/m/Y') }}) @endif
                                                · {{ $lot['available'] }} dispo
                                            </option>
                                        @endforeach
                                    </select>
                                </label>
                                <label>Motif si autre lot <input name="lines[{{ $i }}][override_reason]" placeholder="ex. lot réservé au service"></label>
                                <label>Commentaire <input name="lines[{{ $i }}][comment]"></label>
                            </div>
                        </div>
                    @endforeach

                    <div class="row">
                        @if ($insurers !== [])
                            {{-- Le choix appartient au comptoir : rien ne s'applique tout
                                 seul. Le taux de chaque ligne vient ensuite de ce que
                                 l'organisme couvre sur ce produit. --}}
                            <label>Prise en charge
                                <select name="coverage_insurer_ref">
                                    <option value="">Aucune : le patient paie tout</option>
                                    @foreach ($insurers as $insurer)
                                        <option value="{{ $insurer->ref }}"
                                                @selected(old('coverage_insurer_ref') === $insurer->ref)>
                                            {{ $insurer->name }} · {{ $insurer->kindLabel() }}
                                        </option>
                                    @endforeach
                                </select>
                                <span class="help">
                                    Chaque ligne prend le taux que cet organisme couvre sur son
                                    produit. Les unités sont mises de côté jusqu'à la délivrance.
                                </span>
                            </label>
                        @else
                            <label>Prise en charge
                                <input name="coverage_insurer" value="{{ old('coverage_insurer') }}"
                                       placeholder="assureur ou aide sociale, si le patient en a une">
                                <span class="help">
                                    Aucun organisme n'est fourni par l'application hôte : nommez-le
                                    et donnez son taux. Les unités sont mises de côté jusqu'à la
                                    délivrance.
                                </span>
                            </label>
                            <label>Part prise en charge (%)
                                <input name="coverage_rate" inputmode="numeric" value="{{ old('coverage_rate') }}" placeholder="ex. 80">
                            </label>
                        @endif
                        <label>Référence de l'accord
                            <input name="coverage_reference" value="{{ old('coverage_reference') }}">
                        </label>
                    </div>

                    <label>Observations <input name="notes" value="{{ old('notes') }}"></label>

                    <div class="actions">
                        @if ($paymentFirst)
                            <button type="submit"><x-pharmacie::icon name="fleche" /> Préparer et envoyer en caisse</button>
                        @else
                            <button type="submit"><x-pharmacie::icon name="dispensation" /> Délivrer</button>
                        @endif
                    </div>
                    <p class="muted">
                        @if ($paymentFirst)
                            Rien ne sort du stock maintenant : le patient règle sa part en caisse,
                            puis revient se faire servir. La préparation attend dans « Préparations ».
                        @else
                            Ce qui manque devient un reliquat visible : une dispensation partielle
                            est normale, la cacher ne l'est pas.
                        @endif
                    </p>
                @endif
            </x-pharmacie::card>
        </form>
    @endif
@endsection
