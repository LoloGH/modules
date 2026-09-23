@extends('finance::layout')

@section('title', 'Alertes')

@section('content')
    <x-finance::page title="Alertes"
        sub="Ce qui attend un geste de votre part, relu à chaque affichage." />

    <x-finance::card title="À traiter" hint="{{ count($alerts) }} alerte(s)" flush>
        @if ($alerts === [])
            <div class="bd">
                <x-finance::empty title="Rien à signaler" icon="check">
                    Aucune clôture, aucune demande ni aucune créance n'attend votre intervention.
                </x-finance::empty>
            </div>
        @else
            <div class="tw">
                <table class="stack wide">
                    <thead>
                    <tr><th>Niveau</th><th>Alerte</th><th>Détail</th><th></th></tr>
                    </thead>
                    <tbody>
                    @foreach ($alerts as $alert)
                        <tr>
                            <td data-l="Niveau">
                                <span class="badge {{ $alert->level }}">
                                    {{ ['danger' => 'À faire maintenant', 'warn' => 'En attente', 'info' => 'Pour information'][$alert->level] }}
                                </span>
                            </td>
                            <td data-l="Alerte" class="strong">{{ $alert->title }}</td>
                            <td data-l="Détail">{{ $alert->detail }}</td>
                            <td data-l="" class="acts">
                                @if ($alert->url)
                                    <a class="btn ghost sm" href="{{ $alert->url }}">{{ $alert->action ?? 'Ouvrir' }}</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-finance::card>

    <p class="muted">
        Le module n'envoie ni courriel ni SMS : une alerte est un état de la base.
        Elle disparaît d'elle-même dès que la situation est réglée.
    </p>
@endsection
