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
                        <tr><th>Patient</th><th>Motif</th><th>Ordonnance</th><th>Attente</th><th>État</th></tr>
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
                                    <span class="badge {{ $patient->isCalled() ? 'info' : 'muted' }}">
                                        {{ $patient->isCalled() ? 'Appelé' : 'En attente' }}
                                    </span>
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
