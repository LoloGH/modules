@extends('pharmacie::layout')

@section('title', 'Signalement '.$event->number)

@section('content')
    <a class="back" href="{{ route('pharmacie.vigilance.events.index') }}">
        <x-pharmacie::icon name="retour" /> Retour aux signalements
    </a>

    <x-pharmacie::page :title="'Signalement '.$event->number" :sub="$event->patientLabel()">
        <x-slot:actions>
            <span class="badge {{ $event->severityTone() }}">{{ $event->severityLabel() }}</span>
            <span class="badge {{ $event->statusTone() }}">{{ $event->statusLabel() }}</span>
        </x-slot:actions>
    </x-pharmacie::page>

    <x-pharmacie::card title="Ce qui a été signalé">
        <dl class="facts">
            <div class="f"><dt>Médicament</dt><dd>{{ $event->product?->label() ?? '—' }}</dd></div>
            <div class="f"><dt>Lot</dt><dd class="mono">{{ $event->batch?->number ?? '—' }}</dd></div>
            <div class="f"><dt>Dispensation</dt><dd class="mono">
                @if ($event->dispensation)
                    <a href="{{ route('pharmacie.dispensing.show', $event->dispensation) }}">{{ $event->dispensation->number }}</a>
                @else
                    —
                @endif
            </dd></div>
            <div class="f"><dt>Début des troubles</dt><dd>{{ $event->started_on?->format('d/m/Y') ?? '—' }}</dd></div>
            <div class="f"><dt>Évolution</dt><dd>{{ $event->outcomeLabel() }}</dd></div>
            <div class="f"><dt>Signalé par</dt><dd>{{ $event->reported_by_name ?? '—' }}
                le {{ $event->reported_at?->format('d/m/Y H:i') }}</dd></div>
        </dl>
        <p>{{ $event->description }}</p>
        @if ($event->action_taken)
            <p class="muted">Ce qui a été fait : {{ $event->action_taken }}</p>
        @endif
        @if ($event->transmitted_to)
            <p class="muted">Transmis à {{ $event->transmitted_to }}
                le {{ $event->transmitted_at?->format('d/m/Y') }}.</p>
        @endif
        @if ($event->conclusion)
            <p class="muted">Clos le {{ $event->closed_at?->format('d/m/Y') }} : {{ $event->conclusion }}</p>
        @endif
    </x-pharmacie::card>

    @if ($event->batch)
        <x-pharmacie::card title="Ce lot"
            hint="Plusieurs signalements sur un même lot valent une question">
            <p class="muted">
                {{ $siblings->count() }} autre(s) signalement(s) désignent le lot {{ $event->batch->number }}.
                @if ($recall)
                    Ce lot fait l'objet du rappel
                    <a href="{{ route('pharmacie.vigilance.recalls.show', $recall) }}">{{ $recall->number }}</a>
                    ({{ $recall->statusLabel() }}).
                @elseif ($event->isSerious())
                    Aucun rappel n'a été ouvert sur ce lot.
                @endif
            </p>
            @if ($siblings->isNotEmpty())
                <ul class="plain">
                    @foreach ($siblings as $sibling)
                        <li>
                            <a href="{{ route('pharmacie.vigilance.events.show', $sibling) }}" class="mono">{{ $sibling->number }}</a>
                            — {{ $sibling->severityLabel() }}, {{ $sibling->patientLabel() }}
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-pharmacie::card>
    @endif

    @can('pharmacie.vigilance.manage')
        @if (! $event->isClosed())
            <x-pharmacie::card title="Suivre le signalement"
                hint="Le module n'envoie rien : il note à qui et quand">
                <form method="post" action="{{ route('pharmacie.vigilance.events.transmit', $event) }}" class="inline">
                    @csrf
                    <input name="transmitted_to" placeholder="Centre de pharmacovigilance, autorité…" required>
                    <button type="submit"><x-pharmacie::icon name="fleche" /> Noter la transmission</button>
                </form>

                <form method="post" action="{{ route('pharmacie.vigilance.events.close', $event) }}" class="inline" style="margin-top:.75rem">
                    @csrf
                    <input name="conclusion" placeholder="Conclusion, même négative" required>
                    <button type="submit" class="sm"><x-pharmacie::icon name="check" /> Clore</button>
                </form>
            </x-pharmacie::card>
        @endif
    @endcan
@endsection
