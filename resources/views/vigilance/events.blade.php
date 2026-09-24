@extends('pharmacie::layout')

@section('title', 'Pharmacovigilance')

@section('content')
    <x-pharmacie::page title="Pharmacovigilance"
        sub="Ce qu'un médicament a fait au patient : signalé, relié à son lot, suivi jusqu'à une conclusion." />

    <div class="kpis">
        <x-pharmacie::kpi label="Signalements ouverts" icon="alert" tone="amber" :value="(string) $open" foot="À traiter ou transmis" />
        <x-pharmacie::kpi label="Cas graves" icon="alert" tone="red" :value="(string) $serious" foot="Depuis l'ouverture" />
    </div>

    @can('pharmacie.vigilance.report')
        <x-pharmacie::card title="Signaler un effet indésirable"
            hint="Désigner la dispensation relie d'un coup le patient, le produit et le lot">
            <form method="post" action="{{ route('pharmacie.vigilance.events.store') }}">
                @csrf
                <div class="row">
                    <label>Dispensation
                        <select name="dispensation_id">
                            <option value="">—</option>
                            @foreach ($dispensations as $dispensation)
                                <option value="{{ $dispensation->id }}">
                                    {{ $dispensation->number }} · {{ $dispensation->patient_name ?? $dispensation->patient_id ?? 'patient non désigné' }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                    <label>Produit
                        <select name="product_id">
                            <option value="">—</option>
                            @foreach ($products as $product)
                                <option value="{{ $product->id }}">{{ $product->label() }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Lot
                        <select name="batch_id">
                            <option value="">—</option>
                            @foreach ($batches as $batch)
                                <option value="{{ $batch->id }}">{{ $batch->product?->label() }} · lot {{ $batch->number }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
                <div class="row">
                    <label>Patient <input name="patient_name" placeholder="nom, si la dispensation n'est pas connue"></label>
                    <label>Identifiant patient <input name="patient_id" placeholder="dossier"></label>
                    <label>Début des troubles <input type="date" name="started_on"></label>
                </div>
                <div class="row">
                    <label>Gravité
                        <select name="severity" required>
                            @foreach ($severities as $key => $label)
                                <option value="{{ $key }}" @selected($key === \Keneya\Pharmacie\Models\AdverseEvent::SEVERITY_MODERATE)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Évolution
                        <select name="outcome" required>
                            @foreach ($outcomes as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
                <div class="row">
                    <label>Ce qui a été observé
                        <textarea name="description" rows="3" required
                            placeholder="ex. éruption cutanée apparue le lendemain de la première prise"></textarea>
                    </label>
                </div>
                <div class="row">
                    <label>Ce qui a été fait
                        <textarea name="action_taken" rows="2"
                            placeholder="ex. traitement interrompu, patient orienté vers la consultation"></textarea>
                    </label>
                </div>
                <div class="actions">
                    <button type="submit"><x-pharmacie::icon name="alert" /> Enregistrer le signalement</button>
                </div>
            </form>
        </x-pharmacie::card>
    @endcan

    <x-pharmacie::card title="Signalements" hint="{{ $events->total() }} au total" flush>
        <div class="bd">
            <form method="get" action="{{ route('pharmacie.vigilance.events.index') }}" class="row">
                <label>État
                    <select name="status" onchange="this.form.submit()">
                        <option value="">Tous</option>
                        @foreach ($statuses as $key => $label)
                            <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            </form>
        </div>

        @if ($events->isEmpty())
            <div class="bd">
                <x-pharmacie::empty title="Aucun signalement" icon="check">
                    Rien n'a été rapporté. Un signalement rare n'est pas toujours une bonne nouvelle :
                    c'est souvent qu'il n'a pas été fait.
                </x-pharmacie::empty>
            </div>
        @else
            <div class="tw">
                <table class="stack wide">
                    <thead><tr>
                        <th>N°</th><th>Patient</th><th>Médicament</th><th>Lot</th>
                        <th>Gravité</th><th>Évolution</th><th>État</th><th></th>
                    </tr></thead>
                    <tbody>
                    @foreach ($events as $event)
                        <tr>
                            <td data-l="N°" class="mono strong">{{ $event->number }}</td>
                            <td data-l="Patient">{{ $event->patientLabel() }}</td>
                            <td data-l="Médicament">{{ $event->product?->label() ?? '—' }}</td>
                            <td data-l="Lot" class="mono">{{ $event->batch?->number ?? '—' }}</td>
                            <td data-l="Gravité"><span class="badge {{ $event->severityTone() }}">{{ $event->severityLabel() }}</span></td>
                            <td data-l="Évolution">{{ $event->outcomeLabel() }}</td>
                            <td data-l="État"><span class="badge {{ $event->statusTone() }}">{{ $event->statusLabel() }}</span></td>
                            <td data-l="" class="acts">
                                <a class="btn ghost sm" href="{{ route('pharmacie.vigilance.events.show', $event) }}">Ouvrir</a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-pharmacie::card>
@endsection
