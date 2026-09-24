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
        <form method="post" action="{{ route('pharmacie.dispensing.store') }}">
            @csrf

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
                    <label>Emplacement
                        <select name="location_id" required>
                            @foreach ($locations as $option)
                                <option value="{{ $option->id }}" @selected($option->id === $location->id)>{{ $option->name }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
                @if ($queued)
                    <input type="hidden" name="queue_ref" value="{{ $queued['ref'] }}">
                    <p class="muted">Patient appelé depuis la file : son identité et son ordonnance sont reprises telles quelles.</p>
                @endif
            </x-pharmacie::card>

            <x-pharmacie::card title="Ce qui est délivré" hint="Trois lignes à la fois">
                @if ($products->isEmpty())
                    <p class="muted" style="margin:0">Aucun produit actif au catalogue.</p>
                @else
                    @for ($i = 0; $i < 3; $i++)
                        <div class="row">
                            <label>Produit {{ $i + 1 }}
                                <select name="lines[{{ $i }}][product_id]" @if ($i === 0) required @endif>
                                    @if ($i > 0) <option value="">Aucun</option> @endif
                                    @foreach ($products as $product)
                                        @php($info = $suggestions[$product->id] ?? ['available' => 0, 'batch' => null])
                                        <option value="{{ $product->id }}">
                                            {{ $product->label() }} · {{ $info['available'] }} disponible(s)@if ($info['batch']) · lot {{ $info['batch']->number }}@endif
                                        </option>
                                    @endforeach
                                </select>
                            </label>
                            <label>Prescrit <input name="lines[{{ $i }}][prescribed_quantity]" inputmode="numeric" placeholder="ex. 20"></label>
                            <label>Délivré <input name="lines[{{ $i }}][quantity]" inputmode="numeric" @if ($i === 0) required @endif></label>
                            <label>Posologie <input name="lines[{{ $i }}][posology]" placeholder="1 gélule matin et soir"></label>
                        </div>
                        <div class="row">
                            <label>Lot (facultatif, FEFO par défaut)
                                <select name="lines[{{ $i }}][batch_id]">
                                    <option value="">Le lot qui périme le premier</option>
                                    @foreach ($suggestions as $productId => $info)
                                        @foreach ($info['batches'] as $row)
                                            <option value="{{ $row['batch']->id }}">
                                                {{ $row['batch']->product?->code }} · lot {{ $row['batch']->number }}
                                                @if ($row['batch']->expires_on) (périme le {{ $row['batch']->expires_on->format('d/m/Y') }}) @endif
                                                · {{ $row['available'] }} dispo
                                            </option>
                                        @endforeach
                                    @endforeach
                                </select>
                            </label>
                            <label>Motif si autre lot <input name="lines[{{ $i }}][override_reason]" placeholder="ex. lot réservé au service"></label>
                            <label>Commentaire <input name="lines[{{ $i }}][comment]"></label>
                        </div>
                        <hr style="border:0;border-top:1px solid var(--line-soft);margin:.75rem 0">
                    @endfor

                    <label>Observations <input name="notes" value="{{ old('notes') }}"></label>

                    <div class="actions">
                        <button type="submit"><x-pharmacie::icon name="dispensation" /> Délivrer</button>
                    </div>
                    <p class="muted">
                        Ce qui manque devient un reliquat visible : une dispensation partielle
                        est normale, la cacher ne l'est pas.
                    </p>
                @endif
            </x-pharmacie::card>
        </form>
    @endif
@endsection
