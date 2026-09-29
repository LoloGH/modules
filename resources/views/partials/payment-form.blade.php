{{--
    Le formulaire d'encaissement, partagé par le bureau de caisse et la fenêtre
    de la file d'attente.

    Un seul endroit, parce qu'il s'agit du même geste : ce qui change est d'où
    on le fait, pas ce qu'on enregistre. Deux copies auraient fini par diverger,
    et c'est alors l'encaissement qu'on aurait fait dépendre de la porte par
    laquelle on est entré.

    Paramètres attendus :
      - `$session`     la session de caisse qui reçoit ;
      - `$methods`     les moyens de paiement actifs ;
      - `$acts`        le catalogue, pour le motif ;
      - `$coverage`    les prises en charge proposables, et leurs taux ;
      - `$fromQueue`   le passage appelé, s'il vient de la file ;
      - `$fromInvoice` la facture à régler, si l'on vient d'elle ;
      - `$queueRef`    la file d'où vient le passage ;
      - `$formId`      un identifiant unique : plusieurs formulaires peuvent
                       coexister sur une même page.
--}}
@php
    $formId ??= 'encaissement';
    // « montant-encaissement » sur le bureau de caisse, comme avant : un id
    // stable vaut mieux qu'un id joli.
    $amountId = 'montant-'.$formId;
    $fromQueue ??= null;
    $fromInvoice ??= null;
    $queueRef ??= null;
    // Repris après un refus : le formulaire se rouvre tel qu'il était, mais
    // seulement celui du passage concerné — les autres restent vierges.
    $mine = $fromQueue === null || old('visit_ref') === $fromQueue->ref;
    $recall = fn (string $champ, $defaut = null) => $mine ? old($champ, $defaut) : $defaut;
@endphp

<form method="post" action="{{ route('finance.cash.payments.store', $session) }}"
      id="{{ $formId }}" data-coverage-form data-coverage='@json($fromInvoice ? [] : $coverage)'>
    @csrf
    @if ($fromInvoice)
        <input type="hidden" name="invoice_id" value="{{ $fromInvoice->id }}">
    @endif
    @if ($fromQueue)
        {{-- La visite réglée : l'encaissement la fera avancer chez l'hôte. --}}
        <input type="hidden" name="queue_ref" value="{{ $queueRef }}">
        <input type="hidden" name="visit_ref" value="{{ $fromQueue->ref }}">
        @if ($fromQueue->invoiceId)
            {{-- Déjà facturé ailleurs : l'encaissement se rattache à la pièce,
                 qui tient le décompte de ce qui reste dû. --}}
            <input type="hidden" name="invoice_id" value="{{ $fromQueue->invoiceId }}">
        @endif
    @endif

    <div class="row">
        <label>Moyen de paiement
            <select name="payment_method_id" required>
                @foreach ($methods as $method)
                    <option value="{{ $method->id }}" @selected((string) $recall('payment_method_id') === (string) $method->id)>{{ $method->name }}@if ($method->requires_reference) (référence obligatoire)@endif</option>
                @endforeach
            </select>
        </label>
        <label>Montant
            <input id="{{ $amountId }}" name="amount" class="money" inputmode="numeric"
                   value="{{ $recall('amount', $fromQueue?->expectedAmount() ?? $fromInvoice?->balance()) }}" placeholder="0" required>
            <span class="help">En FCFA, sans décimale. Le tarif de l'acte le remplit automatiquement.</span>
        </label>
    </div>

    <label>Acte encaissé
        <select name="act_id" data-fills="{{ $amountId }}">
            <option value="">Aucun (encaissement hors catalogue)</option>
            @foreach ($acts->groupBy(fn ($act) => $act->center?->name ?? 'Sans centre analytique') as $centre => $group)
                <optgroup label="{{ $centre }}">
                    @foreach ($group as $act)
                        <option value="{{ $act->id }}"
                                @if ($act->standardTariff) data-amount="{{ (int) $act->standardTariff->amount }}" @endif
                                @selected((string) $recall('act_id', $fromQueue?->act?->id) === (string) $act->id)>
                            {{ $act->name }}@if ($act->standardTariff) · {{ $money((int) $act->standardTariff->amount) }}@endif
                        </option>
                    @endforeach
                </optgroup>
            @endforeach
        </select>
        <span class="help">Consultation, analyse, imagerie… Sert à savoir ce que rapporte chaque service.</span>
    </label>

    <div class="row">
        {{-- L'identifiant du patient accompagne toujours son nom : deux
             homonymes ne se confondent pas sur un encaissement. Venu de la
             file, il est celui du dossier et ne se retape pas. --}}
        <label>Identifiant du patient
            <input name="patient_id" value="{{ $recall('patient_id', $fromQueue?->patientRef ?? $fromInvoice?->patient_id) }}"
                   placeholder="ex. PAT-00001" @if ($fromQueue) readonly @endif>
        </label>
        <label>Nom du patient <input name="patient_name" value="{{ $recall('patient_name', $fromQueue?->patientName ?? $fromInvoice?->patient_name) }}"></label>
    </div>

    <div class="row">
        <label>Référence <input name="reference" value="{{ $recall('reference') }}" placeholder="Mobile Money, chèque…"></label>
        <label>Libellé
            <input name="description" value="{{ $recall('description') }}" placeholder="Repris de l'acte si laissé vide">
        </label>
    </div>

    @if (! $fromInvoice && $coverage !== [])
        {{-- Prise en charge : l'organisme paie sa part de l'acte, le patient la
             sienne ; une facture en garde la trace. --}}
        <div class="row">
            <label>Prise en charge
                <select name="insurer_id">
                    <option value="">Aucune : le patient paie tout</option>
                    @foreach (collect($coverage)->groupBy('kind', true) as $kind => $group)
                        <optgroup label="{{ $kind }}">
                            @foreach ($group as $id => $item)
                                <option value="{{ $id }}" @selected((string) $recall('insurer_id') === (string) $id)>{{ $item['name'] }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </label>
            <label>N° de prise en charge <input name="policy_number" value="{{ $recall('policy_number') }}"></label>
        </div>
        <p class="help" data-coverage-hint></p>
    @endif

    <div class="actions">
        <button type="submit"><x-finance::icon name="recette" /> Enregistrer l'encaissement</button>
        @isset($extraActions)
            {!! $extraActions !!}
        @endisset
    </div>
</form>
