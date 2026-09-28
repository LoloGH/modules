@extends('pharmacie::layout')

@section('title', "File d'attente")

@section('content')
    <x-pharmacie::page title="File d'attente"
        sub="Les patients que l'application hôte envoie à la pharmacie." />

    @if ($queues === [])
        <x-pharmacie::card>
            <x-pharmacie::empty title="Aucune file fournie" icon="horloge">
                L'application hôte ne déclare aucune file pour la pharmacie. Tant qu'elle
                n'en fournit pas, cet écran reste vide : le module n'invente pas de patients.
            </x-pharmacie::empty>
        </x-pharmacie::card>
    @else
        @if (count($queues) > 1)
            <div class="switch">
                <span class="lbl">File :</span>
                @foreach ($queues as $queue)
                    <a class="btn ghost sm {{ $current?->ref === $queue->ref ? 'on' : '' }}"
                       href="{{ route('pharmacie.queue.index', ['file' => $queue->ref]) }}">
                        {{ $queue->name }}
                        @if ($queue->waiting > 0)
                            <span class="pip" title="{{ $queue->waiting }} patient(s) en attente"><span class="sr">{{ $queue->waiting }} en attente</span></span>
                        @endif
                    </a>
                @endforeach
            </div>
        @endif

        <x-pharmacie::card :title="$current?->name ?? 'File'" hint="{{ count($patients) }} patient(s) en attente" flush>
            <x-slot:actions>
                @can('pharmacie.queue.call')
                    <form method="post" action="{{ route('pharmacie.queue.call') }}">
                        @csrf
                        <input type="hidden" name="file" value="{{ $current?->ref }}">
                        <button type="submit"><x-pharmacie::icon name="bell" /> Appeler le suivant</button>
                    </form>
                @endcan
            </x-slot:actions>

            @if ($patients === [])
                <div class="bd">
                    <x-pharmacie::empty title="Personne n'attend" icon="check">
                        Les patients envoyés à la pharmacie apparaîtront ici, dans leur ordre d'arrivée.
                    </x-pharmacie::empty>
                </div>
            @else
                <div class="tw">
                    <table class="stack wide">
                        <thead>
                        <tr><th>Patient</th><th>Motif</th><th>Ordonnance</th><th>Attente</th><th>État</th><th>Ce qui reste à faire</th></tr>
                        </thead>
                        <tbody>
                        @foreach ($patients as $patient)
                            <tr>
                                <td data-l="Patient" class="strong">
                                    {{ $patient->patientName }}
                                    <span class="sub mono">{{ $patient->patientId }}</span>
                                </td>
                                <td data-l="Motif">{{ $patient->reason ?? '-' }}</td>
                                <td data-l="Ordonnance" class="mono">{{ $patient->prescriptionRef ?? '-' }}</td>
                                <td data-l="Attente">
                                    @if ($patient->waitedMinutes() !== null)
                                        {{ $patient->waitedMinutes() }} min
                                    @else
                                        -
                                    @endif
                                </td>
                                <td data-l="État">
                                    @php($engage = $work[$patient->ref] ?? ['preparation' => null, 'served' => null])
                                    @if ($engage['preparation'])
                                        <span class="badge warn">À délivrer</span>
                                    @elseif ($engage['served'])
                                        <span class="badge ok">Servi</span>
                                    @else
                                        <span class="badge {{ $patient->isCalled() ? 'info' : 'muted' }}">
                                            {{ $patient->isCalled() ? 'Appelé' : 'En attente' }}
                                        </span>
                                    @endif
                                </td>
                                <td data-l="Ce qui reste à faire" class="acts">
                                    @can('pharmacie.dispensing.create')
                                        @if ($engage['preparation'])
                                            {{-- Revenu de la caisse : sa préparation l'attend. En écrire
                                                 une seconde le renverrait payer une seconde fois. --}}
                                            <a class="btn sm" href="{{ route('pharmacie.preparations.show', $engage['preparation']) }}">
                                                <x-pharmacie::icon name="dispensation" /> Délivrer
                                                <span class="sub mono">{{ $engage['preparation']->number }}</span>
                                            </a>
                                        @elseif ($engage['served'])
                                            {{-- Servi : il n'a plus rien à faire ici. C'est au pharmacien
                                                 de dire où il va, sinon il resterait dans la file. --}}
                                            <details class="sortie">
                                                <summary class="btn sm ghost">
                                                    <x-pharmacie::icon name="check" /> Terminer
                                                </summary>
                                                <div class="menu">
                                                    <form method="post" action="{{ route('pharmacie.queue.close') }}">
                                                        @csrf
                                                        <input type="hidden" name="file" value="{{ $current?->ref }}">
                                                        <input type="hidden" name="patient" value="{{ $patient->ref }}">
                                                        <button type="submit" class="sm block">Clôturer le passage</button>
                                                    </form>
                                                    @if ($destinations !== [])
                                                        <form method="post" action="{{ route('pharmacie.queue.refer') }}">
                                                            @csrf
                                                            <input type="hidden" name="file" value="{{ $current?->ref }}">
                                                            <input type="hidden" name="patient" value="{{ $patient->ref }}">
                                                            <label class="sr">Service</label>
                                                            <select name="destination" required>
                                                                <option value="">Envoyer vers…</option>
                                                                @foreach ($destinations as $destination)
                                                                    <option value="{{ $destination->ref }}">{{ $destination->name }}</option>
                                                                @endforeach
                                                            </select>
                                                            <input name="reason" placeholder="Motif du renvoi">
                                                            <button type="submit" class="sm block">Envoyer</button>
                                                        </form>
                                                    @else
                                                        <p class="muted">
                                                            L'application hôte ne propose aucun service de destination.
                                                        </p>
                                                    @endif
                                                </div>
                                            </details>
                                        @elseif ($patient->isCalled())
                                            <a class="btn sm" href="{{ route('pharmacie.dispensing.create', [
                                                'file' => $current?->ref,
                                                'patient' => $patient->ref,
                                            ]) }}">
                                                <x-pharmacie::icon name="dispensation" /> Préparer
                                            </a>
                                        @endif
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-pharmacie::card>
    @endif
@endsection
