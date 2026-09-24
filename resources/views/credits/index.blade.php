@extends('finance::layout')

@section('title', 'Remises et remboursements')

@section('content')
    <x-finance::page title="Remises et remboursements"
        sub="Ce que l'établissement renonce à réclamer, et ce qu'il rend. Qui demande n'approuve pas." />

    <div class="cols">
        @can('finance.discounts.request')
            <x-finance::card title="Demander une remise" hint="Sur une facture, dans la limite du reste dû">
                @if ($invoices->isEmpty())
                    <p class="muted" style="margin:0">Aucune facture ne peut recevoir une remise.</p>
                @else
                    <form method="post" action="{{ route('finance.credits.discounts.store') }}">
                        @csrf
                        <label>Facture
                            <select name="invoice_id" required>
                                @foreach ($invoices as $invoice)
                                    <option value="{{ $invoice->id }}" @selected((string) old('invoice_id') === (string) $invoice->id)>
                                        {{ $invoice->number }} · {{ $invoice->patient_name ?? $invoice->patient_id }} · reste dû {{ $money($invoice->balance()) }}
                                    </option>
                                @endforeach
                            </select>
                        </label>
                        <div class="row">
                            <label>Montant
                                <input name="amount" class="money" inputmode="numeric" placeholder="0" value="{{ old('amount') }}" required>
                                <span class="help">En FCFA, sans décimale.</span>
                            </label>
                            <label>Motif <input name="reason" value="{{ old('reason') }}" placeholder="ex. Patient indigent" required></label>
                        </div>
                        <div class="actions">
                            <button type="submit" class="ghost"><x-finance::icon name="facture" /> Demander la remise</button>
                        </div>
                    </form>
                @endif
            </x-finance::card>
        @endcan

        @can('finance.refunds.request')
            <x-finance::card title="Demander un remboursement" hint="Encaissement, facture, ou solde du compte">
                <form method="post" action="{{ route('finance.credits.refunds.store') }}">
                    @csrf
                    <div class="row">
                        <label>Origine
                            <select name="source" required>
                                @foreach ($sources as $key => $label)
                                    <option value="{{ $key }}" @selected(old('source') === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>Montant
                            <input name="amount" class="money" inputmode="numeric" placeholder="0" value="{{ old('amount') }}" required>
                        </label>
                    </div>
                    <div class="row">
                        <label>N° d'encaissement
                            <input name="payment_id" inputmode="numeric" value="{{ old('payment_id') }}" placeholder="identifiant">
                            <span class="help">Pour l'origine « Encaissement ».</span>
                        </label>
                        <label>Facture
                            <select name="invoice_id">
                                <option value="">Aucune</option>
                                @foreach ($invoices as $invoice)
                                    <option value="{{ $invoice->id }}" @selected((string) old('invoice_id') === (string) $invoice->id)>
                                        {{ $invoice->number }} · réglé {{ $money($invoice->paid) }}
                                    </option>
                                @endforeach
                            </select>
                        </label>
                    </div>
                    <div class="row">
                        <label>Identifiant du patient
                            <input name="patient_id" value="{{ old('patient_id') }}" placeholder="PAT-000123">
                            <span class="help">Pour l'origine « Solde du compte patient ».</span>
                        </label>
                        <label>Nom du patient <input name="patient_name" value="{{ old('patient_name') }}"></label>
                    </div>
                    <label>Motif <input name="reason" value="{{ old('reason') }}" placeholder="ex. Acte non réalisé" required></label>
                    <div class="actions">
                        <button type="submit" class="ghost"><x-finance::icon name="depense" /> Demander le remboursement</button>
                    </div>
                </form>
            </x-finance::card>
        @endcan
    </div>

    <x-finance::card title="Remises" hint="{{ $discounts->count() }} demande(s)" flush>
        @if ($discounts->isEmpty())
            <div class="bd">
                <x-finance::empty title="Aucune remise" icon="facture">
                    Une remise réduit ce que le patient doit sur une facture.
                </x-finance::empty>
            </div>
        @else
            <div class="tw">
                <table class="stack wide">
                    <thead>
                    <tr><th>N°</th><th>Facture</th><th>Motif</th><th>Demandée par</th><th>Statut</th><th class="num">Montant</th><th></th></tr>
                    </thead>
                    <tbody>
                    @foreach ($discounts as $discount)
                        <tr>
                            <td data-l="N°" class="mono">{{ $discount->number }}</td>
                            <td data-l="Facture">
                                {{ $discount->invoice?->number }}
                                <span class="sub">{{ $discount->invoice?->patient_name ?? $discount->invoice?->patient_id }}</span>
                            </td>
                            <td data-l="Motif">
                                {{ $discount->reason }}
                                @if ($discount->decision_reason)
                                    <span class="sub why">Refusée par {{ $discount->decided_by_name }} : {{ $discount->decision_reason }}</span>
                                @elseif ($discount->decided_by_name)
                                    <span class="sub">Approuvée par {{ $discount->decided_by_name }}</span>
                                @endif
                            </td>
                            <td data-l="Demandée par">{{ $discount->requested_by_name ?? '-' }}</td>
                            <td data-l="Statut"><span class="badge {{ $discount->statusTone() }}">{{ $discount->statusLabel() }}</span></td>
                            <td data-l="Montant" class="num strong">{{ $money($discount->amount) }}</td>
                            <td data-l="" class="acts">
                                @if ($discount->isPending())
                                    @can('finance.discounts.approve')
                                        <form class="inline" method="post" action="{{ route('finance.credits.discounts.decide', $discount) }}">
                                            @csrf
                                            <input type="hidden" name="decision" value="approve">
                                            <button type="submit" class="ghost sm">Approuver</button>
                                        </form>
                                        <form class="inline" method="post" action="{{ route('finance.credits.discounts.decide', $discount) }}">
                                            @csrf
                                            <input type="hidden" name="decision" value="refuse">
                                            <input name="reason" placeholder="Motif du refus" required>
                                            <button type="submit" class="danger sm">Refuser</button>
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

    <x-finance::card title="Remboursements" hint="{{ $refunds->count() }} demande(s)" flush>
        @if ($refunds->isEmpty())
            <div class="bd">
                <x-finance::empty title="Aucun remboursement" icon="depense">
                    Un remboursement approuvé se paie à la caisse, comme tout ce qui sort du tiroir.
                </x-finance::empty>
            </div>
        @else
            <div class="tw">
                <table class="stack wide">
                    <thead>
                    <tr><th>N°</th><th>Patient</th><th>Origine</th><th>Motif</th><th>Statut</th><th class="num">Montant</th><th></th></tr>
                    </thead>
                    <tbody>
                    @foreach ($refunds as $refund)
                        <tr>
                            <td data-l="N°" class="mono">{{ $refund->number }}</td>
                            <td data-l="Patient">
                                {{ $refund->patient_name ?? 'Patient' }}
                                <span class="sub mono">{{ $refund->patient_id }}</span>
                            </td>
                            <td data-l="Origine">
                                {{ $refund->sourceLabel() }}
                                @if ($refund->invoice) <span class="sub">Facture {{ $refund->invoice->number }}</span> @endif
                                @if ($refund->payment) <span class="sub">Encaissement {{ $refund->payment->number }}</span> @endif
                                @if ($refund->disbursement) <span class="sub">Décaissement {{ $refund->disbursement->number }}</span> @endif
                            </td>
                            <td data-l="Motif">
                                {{ $refund->reason }}
                                @if ($refund->decision_reason)
                                    <span class="sub why">Refusé par {{ $refund->decided_by_name }} : {{ $refund->decision_reason }}</span>
                                @elseif ($refund->decided_by_name)
                                    <span class="sub">Approuvé par {{ $refund->decided_by_name }}</span>
                                @endif
                            </td>
                            <td data-l="Statut"><span class="badge {{ $refund->statusTone() }}">{{ $refund->statusLabel() }}</span></td>
                            <td data-l="Montant" class="num strong">{{ $money($refund->amount) }}</td>
                            <td data-l="" class="acts">
                                @if ($refund->isPending())
                                    @can('finance.refunds.approve')
                                        <form class="inline" method="post" action="{{ route('finance.credits.refunds.decide', $refund) }}">
                                            @csrf
                                            <input type="hidden" name="decision" value="approve">
                                            <button type="submit" class="ghost sm">Approuver</button>
                                        </form>
                                        <form class="inline" method="post" action="{{ route('finance.credits.refunds.decide', $refund) }}">
                                            @csrf
                                            <input type="hidden" name="decision" value="refuse">
                                            <input name="reason" placeholder="Motif du refus" required>
                                            <button type="submit" class="danger sm">Refuser</button>
                                        </form>
                                    @endcan
                                @elseif ($refund->isPayable())
                                    <span class="muted">À payer à la caisse</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-finance::card>
@endsection
