@extends('finance::layout')

@section('title', 'File de caisse')

@section('content')
    <x-finance::page
        title="File de caisse"
        sub="Les patients du jour qui attendent un encaissement. Appelez le suivant, puis encaissez dans votre session." />

    @if ($queues->isEmpty())
        <x-finance::card>
            <x-finance::empty title="Aucune file de caisse" icon="horloge">
                L'application hôte ne fournit pas encore de file d'attente à Finance.
            </x-finance::empty>
        </x-finance::card>
    @else
        @if ($queues->count() > 1)
            <nav class="switch" aria-label="Files de caisse">
                <span class="lbl">Caisse :</span>
                @foreach ($queues as $queue)
                    @php ($on = $queue->ref === $current->ref)
                    <a class="btn sm {{ $on ? 'on' : 'ghost' }}"
                       href="{{ route('finance.queue.index', ['file' => $queue->ref, 'session' => $session?->id]) }}"
                       @if ($on) aria-current="page" @endif>
                        <x-finance::icon name="caisse" /> {{ $queue->name }}
                    </a>
                @endforeach
            </nav>
        @endif

        @if ($session === null)
            {{-- Pas de tiroir ouvert : rien ne peut s'encaisser. --}}
            <x-finance::card title="Ouvrez votre session de caisse">
                <p>Pour appeler et encaisser les patients, ouvrez d'abord votre session de caisse.</p>
                <div class="actions">
                    <a class="btn" href="{{ route('finance.cash.index') }}"><x-finance::icon name="caisse" /> Ouvrir ma session</a>
                </div>
            </x-finance::card>
        @else
            <x-finance::card title="{{ $current->name }}" hint="Encaissement dans la session {{ $session->number }} ({{ $session->register->name }})">
                @if ($sessions->count() > 1)
                    <p class="muted">
                        Encaisser dans :
                        @foreach ($sessions as $other)
                            <a class="btn sm {{ $other->is($session) ? 'on' : 'ghost' }}"
                               href="{{ route('finance.queue.index', ['file' => $current->ref, 'session' => $other->id]) }}">{{ $other->register->name }}</a>
                        @endforeach
                    </p>
                @endif

                @can('finance.payments.create')
                    <form method="post" action="{{ route('finance.queue.call') }}" class="inline">
                        @csrf
                        <input type="hidden" name="file" value="{{ $current->ref }}">
                        <input type="hidden" name="session" value="{{ $session->id }}">
                        <button type="submit"><x-finance::icon name="bell" /> Appeler le suivant</button>
                    </form>
                @endcan
            </x-finance::card>
        @endif

        <x-finance::card title="Patients en attente" hint="{{ count($visits) }} patient(s)" flush>
            @if ($visits === [])
                <div class="bd">
                    <x-finance::empty title="Personne n'attend à cette caisse" icon="horloge">
                        La file se remplit à l'enregistrement et aux renvois vers un service payant.
                    </x-finance::empty>
                </div>
            @else
                <div class="tw">
                    <table class="stack">
                        <thead>
                        <tr><th>Ticket</th><th>Patient</th><th>Parcours</th><th>Acte attendu</th><th class="num">Tarif</th><th>État</th><th></th></tr>
                        </thead>
                        <tbody>
                        @foreach ($visits as $visit)
                            <tr>
                                <td data-l="Ticket" class="mono strong">{{ $visit->token }}</td>
                                <td data-l="Patient" class="strong">
                                    {{ $visit->patientName }}
                                    <span class="sub mono">{{ $visit->patientRef }}</span>
                                </td>
                                <td data-l="Parcours">{{ $visit->originService ?? '—' }} → {{ $visit->destinationService ?? '—' }}</td>
                                <td data-l="Acte attendu">{{ $visit->act?->name ?? 'Montant à saisir' }}</td>
                                <td data-l="Tarif" class="num">{{ $visit->expectedAmount() === null ? '—' : $money($visit->expectedAmount()) }}</td>
                                <td data-l="État">
                                    <span class="badge {{ $visit->isCalled() ? 'info' : 'off' }}">{{ $visit->isCalled() ? 'Appelé' : 'En attente' }}</span>
                                </td>
                                <td data-l="" class="acts">
                                    @if ($visit->isCalled() && $session !== null)
                                        @can('finance.payments.create')
                                            <a class="btn sm" href="{{ route('finance.cash.sessions.show', ['session' => $session, 'file' => $current->ref, 'visite' => $visit->ref]) }}#encaisser">
                                                <x-finance::icon name="recette" /> Encaisser
                                            </a>
                                        @endcan
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-finance::card>
    @endif
@endsection
