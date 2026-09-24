@extends('finance::layout')

@section('title', 'Journal d\'audit')

@php
    use Keneya\FinanceCaisse\Support\AuditEvents;

    $periode = $filters['from']->isSameDay($filters['to'])
        ? 'le '.$filters['from']->format('d/m/Y')
        : 'du '.$filters['from']->format('d/m/Y').' au '.$filters['to']->format('d/m/Y');

    // Les valeurs avant/après, en une ligne lisible plutôt qu'en JSON brut.
    $valeurs = static function (?array $values): string {
        if (empty($values)) {
            return '';
        }

        $pairs = [];

        foreach ($values as $key => $value) {
            $pairs[] = $key.' : '.match (true) {
                is_bool($value) => $value ? 'oui' : 'non',
                is_array($value) => implode(', ', array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '…', $value)),
                $value === null => '-',
                default => (string) $value,
            };
        }

        return implode(' · ', $pairs);
    };
@endphp

@section('content')
    <x-finance::page title="Journal d'audit"
        sub="Qui a fait quoi, {{ $periode }}. Une ligne d'audit ne se modifie ni ne se supprime.">
        <x-slot:actions>
            <a class="btn ghost sm" href="{{ route('finance.audit.export', request()->query()) }}">
                <x-finance::icon name="rapport" /> Exporter en CSV
            </a>
        </x-slot:actions>
    </x-finance::page>

    <div class="kpis">
        <x-finance::kpi label="Écritures" icon="document" tone="blue" :value="(string) $stats['count']" :foot="$periode" />
        <x-finance::kpi label="Auteurs" icon="utilisateur" tone="violet" :value="(string) $stats['authors']" />
        <x-finance::kpi label="Types d'événement" icon="controle" tone="green" :value="(string) $stats['events']" />
    </div>

    <x-finance::card flush>
        <div class="bd">
            <form method="get" action="{{ route('finance.audit.index') }}">
                <div class="row">
                    <label>Du <input type="date" name="du" value="{{ $filters['from']->toDateString() }}"></label>
                    <label>Au <input type="date" name="au" value="{{ $filters['to']->toDateString() }}"></label>
                    <label>Événement
                        <select name="evenement">
                            <option value="">Tous</option>
                            @foreach ($groups as $group => $events)
                                <optgroup label="{{ $group }}">
                                    @foreach ($events as $code => $label)
                                        <option value="{{ $code }}" @selected($filters['event'] === $code)>{{ $label }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                    </label>
                    <label>Auteur <input name="auteur" value="{{ $filters['author'] }}" placeholder="Nom ou identifiant"></label>
                    <label>Recherche <input name="q" value="{{ $filters['search'] }}" placeholder="N° de pièce, description"></label>
                </div>
                <div class="actions">
                    <button type="submit"><x-finance::icon name="check" /> Filtrer</button>
                    <a class="btn ghost" href="{{ route('finance.audit.index') }}">Tout voir</a>
                </div>
            </form>
        </div>

        @if ($entries->isEmpty())
            <div class="bd">
                <x-finance::empty title="Aucune écriture" icon="document">
                    Élargissez la période ou retirez un filtre.
                </x-finance::empty>
            </div>
        @else
            <div class="tw">
                <table class="stack wide">
                    <thead>
                    <tr><th>Date</th><th>Événement</th><th>Description</th><th>Auteur</th><th>Objet</th></tr>
                    </thead>
                    <tbody>
                    @foreach ($entries as $entry)
                        <tr>
                            <td data-l="Date">
                                {{ $entry->created_at?->format('d/m/Y H:i:s') }}
                                @if ($entry->ip_address) <span class="sub mono">{{ $entry->ip_address }}</span> @endif
                            </td>
                            <td data-l="Événement">
                                <span class="badge {{ AuditEvents::tone((string) $entry->event) }}">{{ AuditEvents::label((string) $entry->event) }}</span>
                            </td>
                            <td data-l="Description">
                                {{ $entry->description ?? '-' }}
                                @if ($valeurs($entry->old_values) !== '')
                                    <span class="sub">Avant : {{ $valeurs($entry->old_values) }}</span>
                                @endif
                                @if ($valeurs($entry->new_values) !== '')
                                    <span class="sub">Après : {{ $valeurs($entry->new_values) }}</span>
                                @endif
                            </td>
                            <td data-l="Auteur">
                                {{ $entry->user_name ?? 'Compte supprimé' }}
                                <span class="sub mono">{{ $entry->user_id }}</span>
                            </td>
                            <td data-l="Objet" class="mono">
                                {{ $entry->subject_type === null ? '-' : class_basename($entry->subject_type).' n° '.$entry->subject_id }}
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            @if ($entries->hasPages())
                <div class="bd pager">
                    @if ($entries->previousPageUrl()) <a class="btn ghost sm" href="{{ $entries->previousPageUrl() }}">Précédentes</a> @endif
                    <span class="muted">Page {{ $entries->currentPage() }} sur {{ $entries->lastPage() }}</span>
                    @if ($entries->nextPageUrl()) <a class="btn ghost sm" href="{{ $entries->nextPageUrl() }}">Suivantes</a> @endif
                </div>
            @endif
        @endif
    </x-finance::card>
@endsection
