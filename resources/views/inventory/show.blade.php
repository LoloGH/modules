@extends('pharmacie::layout')

@section('title', 'Inventaire '.$inventory->number)

@section('content')
    <a class="back" href="{{ route('pharmacie.inventory.index') }}">
        <x-pharmacie::icon name="retour" /> Retour aux inventaires
    </a>

    <x-pharmacie::page :title="'Inventaire '.$inventory->number"
                       :sub="$inventory->location?->name.' · '.$inventory->scopeLabel()">
        <x-slot:actions>
            <span class="badge {{ $inventory->statusTone() }}">{{ $inventory->statusLabel() }}</span>
        </x-slot:actions>
    </x-pharmacie::page>

    <x-pharmacie::card title="Le comptage">
        <dl class="facts">
            <div class="f"><dt>Compté par</dt><dd>{{ $inventory->counted_by_name ?? '-' }}</dd></div>
            <div class="f"><dt>Validé par</dt><dd>{{ $inventory->validated_by_name ?? '-' }}</dd></div>
            <div class="f"><dt>Validé le</dt><dd>{{ $inventory->validated_at?->format('d/m/Y H:i') ?? '-' }}</dd></div>
            <div class="f"><dt>Écarts</dt><dd>{{ $inventory->gapCount() }} ligne(s)</dd></div>
        </dl>
        @if ($inventory->cancellation_reason)
            <p class="muted">Abandonné : {{ $inventory->cancellation_reason }}</p>
        @endif
    </x-pharmacie::card>

    @if ($inventory->isOpen())
        @can('pharmacie.inventory.count')
            <form method="post" action="{{ route('pharmacie.inventory.count', $inventory) }}">
                @csrf
                <x-pharmacie::card title="Compter" hint="Rien n'est corrigé tant que l'inventaire n'est pas validé" flush>
                    <div class="tw">
                        <table class="stack wide">
                            <thead><tr><th>Produit</th><th>Lot</th><th class="num">Théorique</th><th class="num">Compté</th><th>Motif de l'écart</th></tr></thead>
                            <tbody>
                            @foreach ($inventory->lines as $line)
                                <tr>
                                    <td data-l="Produit" class="strong">{{ $line->label }}</td>
                                    <td data-l="Lot" class="mono">{{ $line->batch?->number }}</td>
                                    <td data-l="Théorique" class="num">{{ $line->expected_quantity }}</td>
                                    <td data-l="Compté" class="num">
                                        <input name="lines[{{ $line->id }}][counted]" inputmode="numeric" value="{{ $line->counted_quantity }}">
                                    </td>
                                    <td data-l="Motif">
                                        <input name="lines[{{ $line->id }}][reason]" value="{{ $line->gap_reason }}" placeholder="si le compte ne tombe pas juste">
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="bd actions">
                        <button type="submit"><x-pharmacie::icon name="check" /> Enregistrer le comptage</button>
                    </div>
                </x-pharmacie::card>
            </form>
        @endcan

        @can('pharmacie.inventory.validate')
            <x-pharmacie::card title="Valider" hint="Celui qui a compté ne valide pas">
                <form method="post" action="{{ route('pharmacie.inventory.validate', $inventory) }}" class="inline">
                    @csrf
                    <button type="submit"><x-pharmacie::icon name="controle" /> Valider et corriger le stock</button>
                    <span class="muted">Les écarts deviennent des ajustements, qui restent au grand livre.</span>
                </form>

                <form method="post" action="{{ route('pharmacie.inventory.cancel', $inventory) }}" class="inline" style="margin-top:.75rem">
                    @csrf
                    <input name="reason" placeholder="Motif de l'abandon" required>
                    <button type="submit" class="danger sm">Abandonner</button>
                </form>
            </x-pharmacie::card>
        @endcan
    @else
        <x-pharmacie::card title="Résultat du comptage" flush>
            <div class="tw">
                <table class="stack wide">
                    <thead><tr><th>Produit</th><th>Lot</th><th class="num">Théorique</th><th class="num">Compté</th><th class="num">Écart</th><th>Motif</th></tr></thead>
                    <tbody>
                    @foreach ($inventory->lines as $line)
                        <tr>
                            <td data-l="Produit" class="strong">{{ $line->label }}</td>
                            <td data-l="Lot" class="mono">{{ $line->batch?->number }}</td>
                            <td data-l="Théorique" class="num">{{ $line->expected_quantity }}</td>
                            <td data-l="Compté" class="num">{{ $line->counted_quantity ?? '-' }}</td>
                            <td data-l="Écart" class="num strong">{{ $line->gap > 0 ? '+' : '' }}{{ $line->gap ?: '-' }}</td>
                            <td data-l="Motif">{{ $line->gap_reason ?? '-' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </x-pharmacie::card>
    @endif
@endsection
