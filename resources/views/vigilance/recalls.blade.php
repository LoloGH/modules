@extends('pharmacie::layout')

@section('title', 'Rappels de lots')

@section('content')
    <x-pharmacie::page title="Rappels de lots"
        sub="Bloquer un lot devenu suspect, et remonter jusqu'aux patients qui l'ont reçu." />

    @can('pharmacie.vigilance.manage')
        <x-pharmacie::card title="Ouvrir un rappel"
            hint="Le lot est bloqué aussitôt : il ne pourra plus être délivré">
            @if ($batches->isEmpty())
                <p class="muted" style="margin:0">Aucun lot à rappeler : le stock n'en connaît pas encore.</p>
            @else
                <form method="post" action="{{ route('pharmacie.vigilance.recalls.store') }}">
                    @csrf
                    <div class="row">
                        <label>Lot
                            <select name="batch_id" required>
                                @foreach ($batches as $batch)
                                    <option value="{{ $batch->id }}">
                                        {{ $batch->product?->label() }} · lot {{ $batch->number }}
                                        @if ($batch->expires_on) (périme le {{ $batch->expires_on->format('d/m/Y') }}) @endif
                                    </option>
                                @endforeach
                            </select>
                        </label>
                        <label>Origine
                            <select name="origin" required>
                                @foreach ($origins as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>Portée
                            <select name="level" required>
                                @foreach ($levels as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>Référence <input name="reference" placeholder="n° de l'avis de rappel"></label>
                    </div>
                    <div class="row">
                        <label>Motif <input name="reason" required
                            placeholder="ex. défaut de fabrication signalé par le laboratoire"></label>
                    </div>
                    <div class="actions">
                        <button type="submit" class="danger"><x-pharmacie::icon name="alert" /> Ouvrir le rappel</button>
                    </div>
                </form>
            @endif
        </x-pharmacie::card>
    @endcan

    <x-pharmacie::card title="Rappels" hint="{{ $recalls->total() }} au total" flush>
        @if ($recalls->isEmpty())
            <div class="bd">
                <x-pharmacie::empty title="Aucun rappel" icon="check">
                    Aucun lot n'a eu à être retiré.
                </x-pharmacie::empty>
            </div>
        @else
            <div class="tw">
                <table class="stack wide">
                    <thead><tr>
                        <th>N°</th><th>Lot</th><th>Produit</th><th>Portée</th>
                        <th class="num">Bloqué</th><th class="num">Patients</th><th>État</th><th></th>
                    </tr></thead>
                    <tbody>
                    @foreach ($recalls as $recall)
                        <tr>
                            <td data-l="N°" class="mono strong">{{ $recall->number }}</td>
                            <td data-l="Lot" class="mono">{{ $recall->batch?->number }}</td>
                            <td data-l="Produit">{{ $recall->batch?->product?->label() }}</td>
                            <td data-l="Portée">{{ $recall->levelLabel() }}</td>
                            <td data-l="Bloqué" class="num">{{ $recall->quantity_blocked }}</td>
                            <td data-l="Patients" class="num">
                                {{ $recall->patients->count() }}
                                @if ($recall->reachesPatients() && $recall->remainingToContact() > 0)
                                    <span class="badge warn">{{ $recall->remainingToContact() }} à joindre</span>
                                @endif
                            </td>
                            <td data-l="État"><span class="badge {{ $recall->statusTone() }}">{{ $recall->statusLabel() }}</span></td>
                            <td data-l="" class="acts">
                                <a class="btn ghost sm" href="{{ route('pharmacie.vigilance.recalls.show', $recall) }}">Ouvrir</a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-pharmacie::card>
@endsection
