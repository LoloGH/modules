@extends('finance::layout')

@section('title', 'Session '.$session->number)

@section('content')
    <p><a href="{{ $isOwner ? route('finance.cash.index') : route('finance.review.index') }}">← Retour</a></p>

    <h1>Session {{ $session->number }} <span class="badge {{ $session->status }}">{{ $session->statusLabel() }}</span></h1>
    <p class="muted">
        {{ $session->register->name }} · Caissier : {{ $session->cashier_name }}
        · Ouverte le {{ $session->opened_at?->format('d/m/Y H:i') }}
        @if ($session->closed_at) · Clôturée le {{ $session->closed_at->format('d/m/Y H:i') }} @endif
    </p>

    <section class="card">
        <h2>Totaux</h2>
        <div class="grid">
            <div class="kpi"><small>Fonds initial</small><strong>{{ $money($totals['opening_float']) }}</strong></div>
            <div class="kpi"><small>Espèces encaissées</small><strong>{{ $money($totals['cash_in']) }}</strong></div>
            <div class="kpi"><small>Espèces décaissées</small><strong>{{ $money($totals['cash_out']) }}</strong></div>
            <div class="kpi"><small>Théorique en espèces (tiroir)</small><strong>{{ $money($totals['expected_cash']) }}</strong></div>
        </div>

        @if (! empty($totals['by_method']))
            <table style="margin-top:1rem">
                <thead><tr><th>Moyen de paiement</th><th class="num">Encaissé</th><th class="num">Décaissé</th></tr></thead>
                <tbody>
                @foreach ($totals['by_method'] as $line)
                    <tr><td>{{ $line['name'] }}</td><td class="num">{{ $money($line['in']) }}</td><td class="num">{{ $money($line['out']) }}</td></tr>
                @endforeach
                </tbody>
            </table>
            <p class="muted">Seules les espèces comptent dans le tiroir ; les autres moyens sont totalisés à part.</p>
        @endif
    </section>

    @if (! $session->isOpen())
        <section class="card">
            <h2>Clôture</h2>
            <div class="grid">
                <div class="kpi"><small>Théorique</small><strong>{{ $money((int) $session->expected_cash) }}</strong></div>
                <div class="kpi"><small>Compté</small><strong>{{ $money((int) $session->counted_cash) }}</strong></div>
                <div class="kpi"><small>Écart</small>
                    <strong class="{{ $session->variance < 0 ? 'neg' : ($session->variance > 0 ? 'pos' : 'zero') }}">
                        {{ $session->variance > 0 ? '+' : '' }}{{ $money((int) $session->variance) }}
                    </strong>
                </div>
            </div>
            @if ($session->variance_reason)
                <p><strong>Justification :</strong> {{ $session->variance_reason }}</p>
            @endif
            @if ($session->isValidated())
                <p class="muted">Validée le {{ $session->validated_at?->format('d/m/Y H:i') }} par {{ $session->validator_name }}@if ($session->validation_note) — {{ $session->validation_note }}@endif</p>
            @endif
        </section>
    @endif

    @if ($session->isOpen() && $isOwner)
        <div class="two">
            @can('finance.payments.create')
                <section class="card">
                    <h2>Encaisser</h2>
                    <form method="post" action="{{ route('finance.cash.payments.store', $session) }}">
                        @csrf
                        <label>Moyen de paiement
                            <select name="payment_method_id" required>
                                @foreach ($methods as $method)
                                    <option value="{{ $method->id }}" @selected((string) old('payment_method_id') === (string) $method->id)>{{ $method->name }}@if ($method->requires_reference) (référence obligatoire)@endif</option>
                                @endforeach
                            </select>
                        </label>
                        <label>Montant (FCFA) <input name="amount" inputmode="numeric" value="{{ old('amount') }}" required></label>
                        <label>Référence (Mobile Money, chèque…) <input name="reference" value="{{ old('reference') }}"></label>
                        <label>Nom du patient <input name="patient_name" value="{{ old('patient_name') }}"></label>
                        <label>Libellé (ex. Consultation) <input name="description" value="{{ old('description') }}"></label>
                        <button type="submit">Enregistrer l'encaissement</button>
                    </form>
                </section>
            @endcan

            @can('finance.disbursements.create')
                <section class="card">
                    <h2>Décaisser</h2>
                    <form method="post" action="{{ route('finance.cash.disbursements.store', $session) }}">
                        @csrf
                        <label>Moyen de paiement
                            <select name="payment_method_id" required>
                                @foreach ($methods as $method)
                                    <option value="{{ $method->id }}">{{ $method->name }}@if ($method->requires_reference) (référence obligatoire)@endif</option>
                                @endforeach
                            </select>
                        </label>
                        <label>Montant (FCFA) <input name="amount" inputmode="numeric" required></label>
                        <label>Motif <input name="reason" required></label>
                        <label>Bénéficiaire <input name="beneficiary"></label>
                        <label>Référence <input name="reference"></label>
                        <button type="submit" class="secondary">Enregistrer le décaissement</button>
                    </form>
                </section>
            @endcan
        </div>
    @endif

    <section class="card">
        <h2>Opérations ({{ $movements->count() }})</h2>
        @if ($movements->isEmpty())
            <p class="muted">Aucune opération dans cette session.</p>
        @else
            <table>
                <thead><tr><th>N°</th><th>Type</th><th>Détail</th><th>Moyen</th><th class="num">Montant</th><th></th></tr></thead>
                <tbody>
                @foreach ($movements as $movement)
                    @php($item = $movement['model'])
                    <tr class="{{ $item->isCancelled() ? 'cancelled' : '' }}">
                        <td>{{ $item->number }}</td>
                        <td>{{ $movement['is_payment'] ? 'Encaissement' : 'Décaissement' }}</td>
                        <td>
                            @if ($movement['is_payment'])
                                {{ $item->patient_name }} @if ($item->description) — {{ $item->description }} @endif
                            @else
                                {{ $item->reason }} @if ($item->beneficiary) ({{ $item->beneficiary }}) @endif
                            @endif
                            @if ($item->reference) <span class="muted">Réf. {{ $item->reference }}</span> @endif
                            @if ($item->isCancelled())
                                <div class="why muted">Annulé par {{ $item->cancelled_by_name }} : {{ $item->cancellation_reason }}</div>
                            @endif
                        </td>
                        <td>{{ $item->method->name }}</td>
                        <td class="num">{{ $movement['is_payment'] ? '' : '−' }}{{ $money($item->amount) }}</td>
                        <td>
                            @if (! $item->isCancelled() && $session->isOpen())
                                @if ($movement['is_payment'])
                                    @can('finance.payments.cancel')
                                        <form class="inline" method="post" action="{{ route('finance.cash.payments.cancel', $item) }}">@csrf
                                            <input name="reason" placeholder="Motif de l'annulation" required>
                                            <button class="danger" type="submit">Annuler</button>
                                        </form>
                                    @endcan
                                @else
                                    @can('finance.disbursements.cancel')
                                        <form class="inline" method="post" action="{{ route('finance.cash.disbursements.cancel', $item) }}">@csrf
                                            <input name="reason" placeholder="Motif de l'annulation" required>
                                            <button class="danger" type="submit">Annuler</button>
                                        </form>
                                    @endcan
                                @endif
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </section>

    @if ($session->isOpen() && $isOwner)
        @can('finance.sessions.close')
            <section class="card">
                <h2>Clôturer la session</h2>
                <p class="muted">Comptez les espèces du tiroir et saisissez le montant. Le système calcule l'écart avec le théorique ({{ $money($totals['expected_cash']) }}). Un écart, en plus ou en moins, doit être justifié.</p>
                <form method="post" action="{{ route('finance.cash.sessions.close', $session) }}">
                    @csrf
                    <label>Montant compté en espèces (FCFA) <input name="counted_cash" inputmode="numeric" value="{{ old('counted_cash') }}" required></label>
                    <label>Justification de l'écart (si le compté diffère du théorique) <textarea name="variance_reason" rows="2">{{ old('variance_reason') }}</textarea></label>
                    <button type="submit">Clôturer la session</button>
                </form>
            </section>
        @endcan
    @endif

    @if ($session->isClosed() && $canReview && ! $isOwner)
        <section class="card">
            <h2>Valider la clôture</h2>
            <form method="post" action="{{ route('finance.review.approve', $session) }}">
                @csrf
                <label>Note (facultatif) <input name="note" value="{{ old('note') }}"></label>
                <button type="submit">Valider la session</button>
            </form>
        </section>
    @endif
@endsection
