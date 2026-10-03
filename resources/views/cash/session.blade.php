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

    {{-- Les quatre chiffres qui comptent pour le tiroir. Quand plusieurs
         caisses le partagent, ils couvrent le tiroir entier : c'est lui qu'on
         compte, et lui seul qui a un fonds. Le detail de CETTE caisse se lit
         plus bas, dans ses mouvements et ses totaux par moyen. --}}
    @php ($tiroir = $drawer ?? $totals)

    @if ($drawer)
        <p class="muted" style="margin:0 0 .5rem">
            <x-finance::icon name="caisse" />
            Tiroir commun à {{ $drawer['count'] }} caisses : {{ $drawer['names'] }}.
            Les chiffres ci-dessous valent pour l'ensemble du tiroir.
        </p>
    @endif

    <div class="kpis">
        <x-finance::kpi label="Fonds initial" icon="caisse" :value="$money($tiroir['opening_float'])"
                        foot="{{ $drawer ? 'Un seul fonds pour le tiroir' : '' }}" />
        <x-finance::kpi label="Espèces encaissées" icon="recette" tone="green" :value="$money($tiroir['cash_in'])" />
        <x-finance::kpi label="Espèces décaissées" icon="depense" tone="red" :value="$money($tiroir['cash_out'])" />
        <x-finance::kpi label="Théorique en tiroir" icon="paiement" tone="blue" :value="$money($tiroir['expected_cash'])"
                        foot="Fonds initial + encaissements − décaissements" />
    </div>

    {{-- Ce que chaque caisse a apporté au tiroir.

         Le tiroir est un seul tas d'espèces, mais il vient de plusieurs
         guichets, et savoir lequel a rapporté quoi est une question
         légitime : c'est elle qui dit si la Caisse Ticket a travaillé, si
         l'échographie a encaissé, où est passée la journée. Les colonnes
         sont celles de chaque session, qui les a gardées en clôturant. --}}
    @if ($drawer)
        <x-finance::card title="Ce que chaque caisse apporte au tiroir"
                         hint="Le tiroir est commun, les recettes restent distinctes" flush>
            <div class="tw">
                <table class="stack">
                    <thead>
                    <tr>
                        <th>Caisse</th><th>Session</th>
                        <th class="num">Fonds</th>
                        <th class="num">Espèces encaissées</th>
                        <th class="num">Espèces décaissées</th>
                        <th class="num">Part du théorique</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($drawer['parts'] as $part)
                        <tr @class(['strong' => $part['number'] === $session->number])>
                            <td data-l="Caisse">{{ $part['name'] }}</td>
                            <td data-l="Session" class="mono">{{ $part['number'] }}</td>
                            <td data-l="Fonds" class="num">{{ $money($part['opening_float']) }}</td>
                            <td data-l="Espèces encaissées" class="num">{{ $money($part['cash_in']) }}</td>
                            <td data-l="Espèces décaissées" class="num">{{ $money($part['cash_out']) }}</td>
                            <td data-l="Part du théorique" class="num">{{ $money($part['expected_cash']) }}</td>
                        </tr>
                    @endforeach
                    <tr class="strong">
                        <td data-l="Caisse">Tiroir</td>
                        <td data-l="Session"></td>
                        <td data-l="Fonds" class="num">{{ $money($drawer['opening_float']) }}</td>
                        <td data-l="Espèces encaissées" class="num">{{ $money($drawer['cash_in']) }}</td>
                        <td data-l="Espèces décaissées" class="num">{{ $money($drawer['cash_out']) }}</td>
                        <td data-l="Part du théorique" class="num">{{ $money($drawer['expected_cash']) }}</td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </x-finance::card>
    @endif

    @if (! $session->isOpen())
        <x-finance::card title="Clôture">
            @if ($drawer)
                <p class="muted">
                    Le tiroir a été compté une seule fois, pour ses {{ $drawer['count'] }} caisses.
                </p>
            @endif
            <dl class="facts">
                <div class="f"><dt>Théorique</dt><dd>{{ $money($drawer ? $drawer['expected_cash'] : (int) $session->expected_cash) }}</dd></div>
                <div class="f"><dt>Compté</dt><dd>{{ $money($drawer ? $drawer['counted_cash'] : (int) $session->counted_cash) }}</dd></div>
                @php ($ecart = $drawer ? $drawer['variance'] : (int) $session->variance)
                <div class="f gap">
                    <dt>Écart</dt>
                    <dd class="{{ $ecart < 0 ? 'neg' : ($ecart > 0 ? 'pos' : 'zero') }}">
                        {{ $ecart > 0 ? '+' : '' }}{{ $money($ecart) }}
                    </dd>
                </div>
            </dl>

            @php ($justification = $drawer ? $drawer['variance_reason'] : $session->variance_reason)
            @if ($justification)
                <p style="margin-bottom:0"><strong>Justification :</strong> {{ $justification }}</p>
            @endif
            @if ($session->isValidated())
                <p class="muted" style="margin-bottom:0">
                    Validée le {{ $session->validated_at?->format('d/m/Y H:i') }} par {{ $session->validator_name }}@if ($session->validation_note) : {{ $session->validation_note }}@endif
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
                    @if ($fromInvoice)
                        <p class="flash ok" id="encaisser">
                            Facture <strong>{{ $fromInvoice->number }}</strong> · {{ $fromInvoice->patient_name ?? $fromInvoice->patient_id }} :
                            solde de {{ $money($fromInvoice->balance()) }}. Un règlement partiel est possible.
                        </p>
                    @endif
                    @if ($fromQueue)
                        <p class="flash ok" id="encaisser">
                            Patient appelé : <strong>{{ $fromQueue->patientName }}</strong>
                            ({{ $fromQueue->patientRef }}), ticket n° {{ $fromQueue->token }}@if ($fromQueue->destinationService), vers {{ $fromQueue->destinationService }}@endif.
                            Vérifiez puis enregistrez : le patient poursuivra alors son parcours.
                        </p>
                        @if ($fromQueue->reason)
                            <p class="flash">
                                {{ $fromQueue->reason }} : {{ $money((int) $fromQueue->expectedAmount()) }} à la charge du patient.
                                Le prix vient du module qui a vendu ; il ne se ressaisit pas ici.
                            </p>
                        @endif
                    @endif
                    @include('finance::partials.payment-form', [
                        'formId' => 'encaissement',
                        'queueRef' => request()->query('file'),
                    ])

                    @include('finance::partials.tariff-fill')
                    @include('finance::partials.coverage-hint')
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
                        <div class="row">
                            <label>Catégorie
                                <select name="category">
                                    <option value="">Non classée</option>
                                    @foreach (\Keneya\FinanceCaisse\Models\Disbursement::categories() as $code => $label)
                                        <option value="{{ $code }}" @selected(old('category') === $code)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label>Référence <input name="reference"></label>
                        </div>
                        <div class="row">
                            {{-- Qui porte la charge : sans centre, la dépense
                                 ne se lit dans le résultat d'aucun service. --}}
                            <label>Centre analytique
                                <select name="analytic_center_id">
                                    <option value="">Non rattachée</option>
                                    @foreach (\Keneya\FinanceCaisse\Models\AnalyticCenter::query()->forCharges()->get() as $center)
                                        <option value="{{ $center->id }}" @selected((string) old('analytic_center_id') === (string) $center->id)>{{ $center->name }}</option>
                                    @endforeach
                                </select>
                                <span class="help">Seuls les centres qui portent des charges.</span>
                            </label>
                        </div>
                        <div class="actions">
                            <button type="submit" class="ghost"><x-finance::icon name="depense" /> Enregistrer le décaissement</button>
                        </div>
                    </form>
                </x-finance::card>
            @endcan
        </div>
    @endif

    @if ($session->isOpen() && $isOwner && $refunds->isNotEmpty())
        @can('finance.disbursements.create')
            <x-finance::card title="Remboursements à payer"
                             hint="{{ $refunds->count() }} approuvé(s)" flush>
                <div class="tw">
                    <table class="stack wide">
                        <thead>
                        <tr><th>N°</th><th>Patient</th><th>Motif</th><th class="num">Montant</th><th>Payer par</th><th></th></tr>
                        </thead>
                        <tbody>
                        @foreach ($refunds as $refund)
                            <tr>
                                <td data-l="N°" class="mono">{{ $refund->number }}</td>
                                <td data-l="Patient">
                                    {{ $refund->patient_name ?? 'Patient' }}
                                    <span class="sub mono">{{ $refund->patient_id }}</span>
                                </td>
                                <td data-l="Motif">
                                    {{ $refund->reason }}
                                    <span class="sub">{{ $refund->sourceLabel() }} · approuvé par {{ $refund->decided_by_name }}</span>
                                </td>
                                <td data-l="Montant" class="num strong">−{{ $money($refund->amount) }}</td>
                                <td data-l="Payer par" colspan="2">
                                    <form class="inline" method="post" action="{{ route('finance.cash.refunds.pay', [$session, $refund]) }}">
                                        @csrf
                                        <label class="sr" for="moyen-{{ $refund->id }}">Moyen de paiement</label>
                                        <select id="moyen-{{ $refund->id }}" name="payment_method_id" required>
                                            @foreach ($methods as $method)
                                                @continue(in_array($method->kind, ['patient_account', 'insurance'], true))
                                                <option value="{{ $method->id }}">{{ $method->name }}</option>
                                            @endforeach
                                        </select>
                                        <button type="submit" class="ghost sm">Payer</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </x-finance::card>
        @endcan
    @endif

    @if ($session->isOpen() && $isOwner)
        @can('finance.deposits.create')
            <x-finance::card title="Avance sur compte patient"
                             hint="De l'argent reçu d'avance : ce n'est pas encore une recette">
                <form method="post" action="{{ route('finance.cash.deposits.store', $session) }}">
                    @csrf
                    <div class="row">
                        <label>Identifiant du patient <input name="patient_id" value="{{ old('patient_id') }}" placeholder="PAT-000123" required></label>
                        <label>Nom du patient <input name="patient_name" value="{{ old('patient_name') }}"></label>
                    </div>
                    <div class="row">
                        <label>Moyen de paiement
                            <select name="payment_method_id" required>
                                @foreach ($methods as $method)
                                    @continue(in_array($method->kind, ['patient_account', 'insurance'], true))
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
                        <label>Motif <input name="note" value="{{ old('note') }}" placeholder="ex. Avance sur hospitalisation"></label>
                        <label>Référence <input name="reference" value="{{ old('reference') }}"></label>
                    </div>
                    <div class="actions">
                        <button type="submit" class="ghost"><x-finance::icon name="recette" /> Enregistrer l'avance</button>
                    </div>
                </form>
                <p class="muted">
                    L'avance entre dans le tiroir et reste acquise au patient. Elle réglera
                    ses prochains actes, encaissés au moyen « Compte patient ».
                </p>
            </x-finance::card>
        @endcan
    @endif

    @can('finance.audit.view')
        <p class="muted">
            <a class="btn ghost sm" href="{{ route('finance.audit.index', ['q' => $session->number, 'du' => $session->created_at?->toDateString()]) }}">
                <x-finance::icon name="document" /> Journal d'audit de cette session
            </a>
        </p>
    @endcan

    <x-finance::card title="Opérations" hint="{{ $movements->count() }} au total" flush>
        @if ($movements->isEmpty())
            <div class="bd">
                <x-finance::empty title="Aucune opération dans cette session" icon="paiement">
                    Les encaissements, avances et décaissements apparaîtront ici,
                    annulations comprises.
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
                                @php($kind = $movement['kind'])
                                <span class="badge {{ ['payment' => 'ok', 'deposit' => 'info', 'disbursement' => 'muted'][$kind] }}">
                                    {{ ['payment' => 'Encaissement', 'deposit' => 'Avance', 'disbursement' => 'Décaissement'][$kind] }}
                                </span>
                            </td>
                            <td data-l="Détail">
                                @if ($kind === 'deposit')
                                    {{ $item->note ?? 'Avance sur compte patient' }}
                                    <span class="sub">{{ $item->patient_name }}@if ($item->patient_name && $item->patient_id) · @endif<span class="mono">{{ $item->patient_id }}</span></span>
                                @elseif ($kind === 'payment')
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
                            <td data-l="Montant" class="num strong">{{ $kind === 'disbursement' ? '−' : '' }}{{ $money($item->amount) }}</td>
                            <td data-l="" class="acts">
                                @php($receipts = ['payment' => 'finance.cash.payments.receipt', 'deposit' => 'finance.cash.deposits.receipt', 'disbursement' => 'finance.cash.disbursements.receipt'])
                                <a class="btn ghost sm" target="_blank" rel="noopener"
                                   href="{{ route($receipts[$kind], $item) }}">{{ $kind === 'disbursement' ? 'Bon' : 'Reçu' }}</a>
                                @if (! $item->isCancelled() && $session->isOpen())
                                    @php($route = ['payment' => 'finance.cash.payments.cancel', 'deposit' => 'finance.cash.deposits.cancel', 'disbursement' => 'finance.cash.disbursements.cancel'][$kind])
                                    @can($kind === 'disbursement' ? 'finance.disbursements.cancel' : 'finance.payments.cancel')
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
            <x-finance::card title="{{ $drawer ? 'Clôturer le tiroir' : 'Clôturer la session' }}">
                <p class="muted">
                    Comptez les espèces du tiroir et saisissez le montant. Le système calcule
                    l'écart avec le théorique (<strong>{{ $money($drawer ? $drawer['expected_cash'] : $totals['expected_cash']) }}</strong>).
                    Un écart, en plus ou en moins, doit être justifié.
                </p>
                @if ($drawer)
                    {{-- Un tiroir, un comptage. Clôturer les caisses une par une
                         aurait demandé de compter trois fois le même tas, et de
                         répartir le résultat au jugé. --}}
                    <p class="muted">
                        Ce tiroir porte {{ $drawer['count'] }} caisses ({{ $drawer['names'] }}) : comptez-le
                        <strong>une seule fois</strong>. Les {{ $drawer['count'] }} caisses se clôturent ensemble.
                    </p>
                @endif
                <form method="post" action="{{ route('finance.cash.sessions.close', $session) }}">
                    @csrf
                    <label>Montant compté en espèces
                        <input name="counted_cash" class="money" inputmode="numeric" value="{{ old('counted_cash') }}" placeholder="0" required>
                        <span class="help">En FCFA, ce que vous avez réellement compté{{ $drawer ? ' dans le tiroir, pour toutes ses caisses' : '' }}.</span>
                    </label>
                    <label>Justification de l'écart
                        <textarea name="variance_reason" rows="2" placeholder="Obligatoire si le compté diffère du théorique">{{ old('variance_reason') }}</textarea>
                    </label>
                    <div class="actions">
                        <button type="submit" class="lg"><x-finance::icon name="verrou" />
                            {{ $drawer ? 'Clôturer le tiroir et ses caisses' : 'Clôturer la session' }}</button>
                        <span class="muted" style="font-size:.8125rem">La session ne pourra plus être modifiée après la clôture.</span>
                    </div>
                </form>
            </x-finance::card>
        @endcan
    @endif

    @if ($session->isClosed() && $canReview && ! $isOwner)
        <x-finance::card title="{{ $drawer ? 'Valider la clôture du tiroir' : 'Valider la clôture' }}"
                         hint="Le caissier ne valide jamais sa propre session">
            @if ($drawer)
                {{-- Un tiroir compté une fois ne se contrôle qu'une fois. --}}
                <p class="muted">
                    Ce tiroir porte {{ $drawer['count'] }} caisses ({{ $drawer['names'] }}), comptées
                    ensemble : <strong>une seule validation</strong> les couvre toutes.
                </p>
            @endif
            <form method="post" action="{{ route('finance.review.approve', $session) }}">
                @csrf
                <label>Note (facultatif) <input name="note" value="{{ old('note') }}"></label>
                <div class="actions">
                    <button type="submit" class="lg"><x-finance::icon name="check" />
                        {{ $drawer ? 'Valider le tiroir et ses caisses' : 'Valider la session' }}</button>
                </div>
            </form>
        </x-finance::card>
    @endif
@endsection
