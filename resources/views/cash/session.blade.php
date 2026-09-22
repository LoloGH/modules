@extends('finance::layout')

@section('title', 'Session '.$session->number)

@section('content')
    <a class="back" href="{{ $isOwner ? route('finance.cash.index') : route('finance.review.index') }}">
        <x-finance::icon name="retour" /> Retour
    </a>

    @if ($openSessions->count() > 1)
        <nav class="switch" aria-label="Mes caisses ouvertes">
            <span class="lbl">Mes caisses ouvertes :</span>
            @foreach ($openSessions as $other)
                @php ($current = $other->is($session))
                <a class="btn sm {{ $current ? 'on' : 'ghost' }}"
                   href="{{ route('finance.cash.sessions.show', $other) }}"
                   @if ($current) aria-current="page" @endif>
                    <x-finance::icon name="caisse" /> {{ $other->register->name }}
                </a>
            @endforeach
        </nav>
    @endif

    <x-finance::page
        title="Session {{ $session->number }}"
        sub="{{ $session->register->name }} · Caissier : {{ $session->cashier_name }} · Ouverte le {{ $session->opened_at?->format('d/m/Y H:i') }}{{ $session->closed_at ? ' · Clôturée le '.$session->closed_at->format('d/m/Y H:i') : '' }}">
        <x-slot:actions>
            <span class="badge {{ $session->status }}">
                @if ($session->isOpen())<span class="pt"></span>@endif{{ $session->statusLabel() }}
            </span>
        </x-slot:actions>
    </x-finance::page>

    {{-- Les quatre chiffres qui comptent pour le tiroir. --}}
    <div class="kpis">
        <x-finance::kpi label="Fonds initial" icon="caisse" :value="$money($totals['opening_float'])" />
        <x-finance::kpi label="Espèces encaissées" icon="recette" tone="green" :value="$money($totals['cash_in'])" />
        <x-finance::kpi label="Espèces décaissées" icon="depense" tone="red" :value="$money($totals['cash_out'])" />
        <x-finance::kpi label="Théorique en tiroir" icon="paiement" tone="blue" :value="$money($totals['expected_cash'])"
                        foot="Fonds initial + encaissements − décaissements" />
    </div>

    @if (! $session->isOpen())
        <x-finance::card title="Clôture">
            <dl class="facts">
                <div class="f"><dt>Théorique</dt><dd>{{ $money((int) $session->expected_cash) }}</dd></div>
                <div class="f"><dt>Compté</dt><dd>{{ $money((int) $session->counted_cash) }}</dd></div>
                <div class="f gap">
                    <dt>Écart</dt>
                    <dd class="{{ $session->variance < 0 ? 'neg' : ($session->variance > 0 ? 'pos' : 'zero') }}">
                        {{ $session->variance > 0 ? '+' : '' }}{{ $money((int) $session->variance) }}
                    </dd>
                </div>
            </dl>

            @if ($session->variance_reason)
                <p style="margin-bottom:0"><strong>Justification :</strong> {{ $session->variance_reason }}</p>
            @endif
            @if ($session->isValidated())
                <p class="muted" style="margin-bottom:0">
                    Validée le {{ $session->validated_at?->format('d/m/Y H:i') }} par {{ $session->validator_name }}@if ($session->validation_note) — {{ $session->validation_note }}@endif
                </p>
            @endif
        </x-finance::card>
    @endif

    @if ($session->isOpen() && $isOwner)
        <div class="cols">
            @can('finance.payments.create')
                <x-finance::card title="Encaisser">
                    @if ($reopenQueue)
                        <p class="flash err">
                            Ce patient n'attend plus d'encaissement ici (déjà encaissé, orienté, ou lien périmé).
                            <a class="btn sm" href="{{ route('finance.queue.index', ['file' => $reopenQueue]) }}">
                                <x-finance::icon name="retour" /> Rouvrir depuis la file
                            </a>
                        </p>
                    @endif
                    @if ($fromQueue)
                        <p class="flash ok" id="encaisser">
                            Patient appelé : <strong>{{ $fromQueue->patientName }}</strong>
                            ({{ $fromQueue->patientRef }}), ticket n° {{ $fromQueue->token }}@if ($fromQueue->destinationService), vers {{ $fromQueue->destinationService }}@endif.
                            Vérifiez puis enregistrez : le patient poursuivra alors son parcours.
                        </p>
                    @endif
                    <form method="post" action="{{ route('finance.cash.payments.store', $session) }}">
                        @csrf
                        @if ($fromQueue)
                            {{-- La visite réglée : l'encaissement la fera avancer chez l'hôte. --}}
                            <input type="hidden" name="queue_ref" value="{{ request()->query('file') }}">
                            <input type="hidden" name="visit_ref" value="{{ $fromQueue->ref }}">
                        @endif
                        <div class="row">
                            <label>Moyen de paiement
                                <select name="payment_method_id" required>
                                    @foreach ($methods as $method)
                                        <option value="{{ $method->id }}" @selected((string) old('payment_method_id') === (string) $method->id)>{{ $method->name }}@if ($method->requires_reference) (référence obligatoire)@endif</option>
                                    @endforeach
                                </select>
                            </label>
                            <label>Montant
                                <input id="montant-encaissement" name="amount" class="money" inputmode="numeric"
                                       value="{{ old('amount', $fromQueue?->expectedAmount()) }}" placeholder="0" required>
                                <span class="help">En FCFA, sans décimale. Le tarif de l'acte le remplit automatiquement.</span>
                            </label>
                        </div>
                        <label>Acte encaissé
                            <select name="act_id" data-fills="montant-encaissement">
                                <option value="">— Aucun (encaissement hors catalogue)</option>
                                @foreach ($acts->groupBy(fn ($act) => $act->center?->name ?? 'Sans centre analytique') as $centre => $group)
                                    <optgroup label="{{ $centre }}">
                                        @foreach ($group as $act)
                                            <option value="{{ $act->id }}"
                                                    @if ($act->standardTariff) data-amount="{{ (int) $act->standardTariff->amount }}" @endif
                                                    @selected((string) old('act_id', $fromQueue?->act?->id) === (string) $act->id)>
                                                {{ $act->name }}@if ($act->standardTariff) — {{ $money((int) $act->standardTariff->amount) }}@endif
                                            </option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                            <span class="help">Consultation, analyse, imagerie… Sert à savoir ce que rapporte chaque service.</span>
                        </label>
                        <div class="row">
                            {{-- L'identifiant du patient accompagne toujours son nom : deux
                                 homonymes ne se confondent pas sur un encaissement. Venu de
                                 la file, il est celui du dossier et ne se retape pas. --}}
                            <label>Identifiant du patient
                                <input name="patient_id" value="{{ old('patient_id', $fromQueue?->patientRef) }}"
                                       placeholder="ex. PAT-00001" @if ($fromQueue) readonly @endif>
                            </label>
                            <label>Nom du patient <input name="patient_name" value="{{ old('patient_name', $fromQueue?->patientName) }}"></label>
                        </div>
                        <label>Référence <input name="reference" value="{{ old('reference') }}" placeholder="Mobile Money, chèque…"></label>
                        <label>Libellé
                            <input name="description" value="{{ old('description') }}" placeholder="Repris de l'acte si laissé vide">
                        </label>
                        <div class="actions">
                            <button type="submit"><x-finance::icon name="recette" /> Enregistrer l'encaissement</button>
                        </div>
                    </form>

                    @include('finance::partials.tariff-fill')
                </x-finance::card>
            @endcan

            @can('finance.disbursements.create')
                <x-finance::card title="Décaisser" hint="Jamais plus que ce que contient le tiroir">
                    <form method="post" action="{{ route('finance.cash.disbursements.store', $session) }}">
                        @csrf
                        <div class="row">
                            <label>Moyen de paiement
                                <select name="payment_method_id" required>
                                    @foreach ($methods as $method)
                                        <option value="{{ $method->id }}">{{ $method->name }}@if ($method->requires_reference) (référence obligatoire)@endif</option>
                                    @endforeach
                                </select>
                            </label>
                            <label>Montant
                                <input name="amount" class="money" inputmode="numeric" placeholder="0" required>
                                <span class="help">En FCFA, sans décimale.</span>
                            </label>
                        </div>
                        <div class="row">
                            <label>Motif <input name="reason" required placeholder="ex. Achat de fournitures"></label>
                            <label>Bénéficiaire <input name="beneficiary"></label>
                        </div>
                        <label>Référence <input name="reference"></label>
                        <div class="actions">
                            <button type="submit" class="ghost"><x-finance::icon name="depense" /> Enregistrer le décaissement</button>
                        </div>
                    </form>
                </x-finance::card>
            @endcan
        </div>
    @endif

    <x-finance::card title="Opérations" hint="{{ $movements->count() }} au total" flush>
        @if ($movements->isEmpty())
            <div class="bd">
                <x-finance::empty title="Aucune opération dans cette session" icon="paiement">
                    Les encaissements et décaissements apparaîtront ici, annulations comprises.
                </x-finance::empty>
            </div>
        @else
            <div class="tw">
                <table class="stack wide">
                    <thead>
                    <tr><th>N°</th><th>Type</th><th>Détail</th><th>Moyen</th><th class="num">Montant</th><th></th></tr>
                    </thead>
                    <tbody>
                    @foreach ($movements as $movement)
                        @php($item = $movement['model'])
                        <tr class="{{ $item->isCancelled() ? 'cancelled' : '' }}">
                            <td data-l="N°" class="mono">{{ $item->number }}</td>
                            <td data-l="Type">
                                <span class="badge {{ $movement['is_payment'] ? 'ok' : 'muted' }}">
                                    {{ $movement['is_payment'] ? 'Encaissement' : 'Décaissement' }}
                                </span>
                            </td>
                            <td data-l="Détail">
                                @if ($movement['is_payment'])
                                    {{ $item->act?->name ?? $item->description ?? 'Encaissement' }}
                                    @if ($item->act?->center) <span class="badge muted">{{ $item->act->center->name }}</span> @endif
                                    @if ($item->patient_name || $item->patient_id)
                                        <span class="sub">{{ $item->patient_name }}@if ($item->patient_name && $item->patient_id) · @endif<span class="mono">{{ $item->patient_id }}</span></span>
                                    @endif
                                    @if ($item->description && $item->description !== $item->act?->name)
                                        <span class="sub">{{ $item->description }}</span>
                                    @endif
                                @else
                                    {{ $item->reason }} @if ($item->beneficiary) ({{ $item->beneficiary }}) @endif
                                @endif
                                @if ($item->reference) <span class="sub">Réf. {{ $item->reference }}</span> @endif
                                @if ($item->isCancelled())
                                    <span class="sub why">Annulé par {{ $item->cancelled_by_name }} : {{ $item->cancellation_reason }}</span>
                                @endif
                            </td>
                            <td data-l="Moyen">{{ $item->method->name }}</td>
                            <td data-l="Montant" class="num strong">{{ $movement['is_payment'] ? '' : '−' }}{{ $money($item->amount) }}</td>
                            <td data-l="" class="acts">
                                @if (! $item->isCancelled() && $session->isOpen())
                                    @php($route = $movement['is_payment'] ? 'finance.cash.payments.cancel' : 'finance.cash.disbursements.cancel')
                                    @can($movement['is_payment'] ? 'finance.payments.cancel' : 'finance.disbursements.cancel')
                                        <form class="inline" method="post" action="{{ route($route, $item) }}">@csrf
                                            <input name="reason" placeholder="Motif de l'annulation" required>
                                            <button class="danger sm" type="submit">Annuler</button>
                                        </form>
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

    {{-- Le récapitulatif par moyen vient juste avant la clôture : c'est ce
         qu'on relit pour compter le tiroir. --}}
    @if (! empty($totals['by_method']))
        <x-finance::card title="Totaux par moyen de paiement"
                         hint="Seules les espèces comptent dans le tiroir" flush>
            <div class="tw">
                <table class="stack">
                    <thead><tr><th>Moyen de paiement</th><th class="num">Encaissé</th><th class="num">Décaissé</th></tr></thead>
                    <tbody>
                    @foreach ($totals['by_method'] as $line)
                        <tr>
                            <td data-l="Moyen">{{ $line['name'] }}</td>
                            <td data-l="Encaissé" class="num">{{ $money($line['in']) }}</td>
                            <td data-l="Décaissé" class="num">{{ $money($line['out']) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </x-finance::card>
    @endif

    @if ($session->isOpen() && $isOwner)
        @can('finance.sessions.close')
            <x-finance::card title="Clôturer la session">
                <p class="muted">
                    Comptez les espèces du tiroir et saisissez le montant. Le système calcule
                    l'écart avec le théorique (<strong>{{ $money($totals['expected_cash']) }}</strong>).
                    Un écart, en plus ou en moins, doit être justifié.
                </p>
                <form method="post" action="{{ route('finance.cash.sessions.close', $session) }}">
                    @csrf
                    <label>Montant compté en espèces
                        <input name="counted_cash" class="money" inputmode="numeric" value="{{ old('counted_cash') }}" placeholder="0" required>
                        <span class="help">En FCFA, ce que vous avez réellement compté.</span>
                    </label>
                    <label>Justification de l'écart
                        <textarea name="variance_reason" rows="2" placeholder="Obligatoire si le compté diffère du théorique">{{ old('variance_reason') }}</textarea>
                    </label>
                    <div class="actions">
                        <button type="submit" class="lg"><x-finance::icon name="verrou" /> Clôturer la session</button>
                        <span class="muted" style="font-size:.8125rem">La session ne pourra plus être modifiée après la clôture.</span>
                    </div>
                </form>
            </x-finance::card>
        @endcan
    @endif

    @if ($session->isClosed() && $canReview && ! $isOwner)
        <x-finance::card title="Valider la clôture" hint="Le caissier ne valide jamais sa propre session">
            <form method="post" action="{{ route('finance.review.approve', $session) }}">
                @csrf
                <label>Note (facultatif) <input name="note" value="{{ old('note') }}"></label>
                <div class="actions">
                    <button type="submit" class="lg"><x-finance::icon name="check" /> Valider la session</button>
                </div>
            </form>
        </x-finance::card>
    @endif
@endsection
