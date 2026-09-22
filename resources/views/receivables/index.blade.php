@extends('finance::layout')

@section('title', 'Créances')

@section('content')
    <x-finance::page title="Créances" sub="Ce que les patients et les assureurs doivent encore, avec l'échéance et l'ancienneté." />

    <div class="kpis">
        <x-finance::kpi label="Créances patients" icon="creance" tone="red" :value="$money($totals['patients'])" />
        <x-finance::kpi label="Créances assurances et aides" icon="assurance" tone="violet" :value="$money($totals['insurers'])"
                        :foot="collect($totals['byKind'])->map(fn ($v, $k) => $k.' '.$money($v))->implode(' · ') ?: null" />
        <x-finance::kpi label="Total à recouvrer" icon="creance" tone="blue" :value="$money($totals['patients'] + $totals['insurers'])" />
        <x-finance::kpi label="Échu ({{ $type === 'assurances' ? 'assurances' : 'patients' }})" icon="alert" tone="amber" :value="$money($totals['overdue'])"
                        :foot="$days === 0 ? 'Payable dès l\'émission' : 'Délai : '.$days.' jour(s)'" />
    </div>

    <nav class="tabs">
        <a href="{{ route('finance.receivables.index') }}" class="{{ $type === 'patients' ? 'on' : '' }}">Patients</a>
        <a href="{{ route('finance.receivables.index', ['type' => 'assurances']) }}" class="{{ $type === 'assurances' ? 'on' : '' }}">Assurances et aides sociales</a>
    </nav>

    <div class="cols wide">
        <x-finance::card flush>
            <div class="bd">
                <form method="get" action="{{ route('finance.receivables.index') }}">
                    <input type="hidden" name="type" value="{{ $type }}">
                    <div class="row">
                        @if ($type === 'assurances')
                            <label>Nature
                                <select name="nature">
                                    <option value="">Toutes</option>
                                    @foreach (\Keneya\FinanceCaisse\Models\Insurer::kindLabels() as $code => $label)
                                        <option value="{{ $code }}" @selected($kind === $code)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label>Organisme
                                <select name="assureur">
                                    <option value="">Tous</option>
                                    @foreach ($insurers as $insurer)
                                        <option value="{{ $insurer->id }}" @selected($insurerId === $insurer->id)>{{ $insurer->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                        @endif
                        <label>Échéance
                            <select name="statut">
                                <option value="">Toutes</option>
                                <option value="a-echoir" @selected($status === 'a-echoir')>À échoir</option>
                                <option value="echue" @selected($status === 'echue')>Échues</option>
                            </select>
                        </label>
                        <label>Recherche <input name="q" value="{{ $search }}" placeholder="Facture, patient, identifiant…"></label>
                    </div>
                    <div class="actions">
                        <button type="submit" class="sm">Filtrer</button>
                        <a class="btn ghost sm" href="{{ route('finance.receivables.index', $type === 'assurances' ? ['type' => 'assurances'] : []) }}">Réinitialiser</a>
                    </div>
                </form>
            </div>

            @if ($invoices->isEmpty())
                <div class="bd">
                    <x-finance::empty title="Aucune créance" icon="creance">
                        {{ $type === 'assurances' ? 'Les assureurs ne doivent rien pour ces critères.' : 'Les patients ne doivent rien pour ces critères.' }}
                    </x-finance::empty>
                </div>
            @else
                <div class="tw">
                    <table class="stack wide">
                        <thead>
                        <tr>
                            <th>Facture</th>
                            @if ($type === 'assurances') <th>Organisme</th> @endif
                            <th>Patient</th><th>Échéance</th>
                            <th class="num">Montant dû</th><th class="num">Payé</th><th class="num">Solde</th><th>Statut</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($invoices as $invoice)
                            @php($due = $dueDate($invoice))
                            @php($late = (int) $due->diffInDays(today(), false))
                            <tr>
                                <td data-l="Facture" class="mono">
                                    <a href="{{ route('finance.invoices.show', $invoice) }}">{{ $invoice->number }}</a>
                                    <span class="sub">Émise le {{ $invoice->created_at?->format('d/m/Y') }}</span>
                                </td>
                                @if ($type === 'assurances')
                                    <td data-l="Organisme">{{ $invoice->insurer?->name }}
                                        <span class="sub">{{ $invoice->insurer?->kindLabel() }}@if ($invoice->policy_number) · PEC {{ $invoice->policy_number }}@endif</span></td>
                                @endif
                                <td data-l="Patient" class="strong">{{ $invoice->patient_name ?? '—' }}
                                    @if ($invoice->patient_id) <span class="sub mono">{{ $invoice->patient_id }}</span> @endif</td>
                                <td data-l="Échéance">{{ $due->format('d/m/Y') }}</td>
                                @if ($type === 'assurances')
                                    <td data-l="Montant dû" class="num">{{ $money($invoice->insurer_share) }}</td>
                                    <td data-l="Payé" class="num">{{ $money($invoice->insurer_paid) }}
                                        @if ($invoice->insurer_rejected > 0) <span class="sub">rejeté {{ $money($invoice->insurer_rejected) }}</span> @endif</td>
                                    <td data-l="Solde" class="num strong">{{ $money($invoice->insurerOutstanding()) }}</td>
                                @else
                                    <td data-l="Montant dû" class="num">{{ $money($invoice->patientDue()) }}</td>
                                    <td data-l="Payé" class="num">{{ $money($invoice->paid) }}</td>
                                    <td data-l="Solde" class="num strong">{{ $money($invoice->balance()) }}</td>
                                @endif
                                <td data-l="Statut">
                                    @if ($late > 0)
                                        <span class="badge danger">Échue · {{ $late }} j</span>
                                    @else
                                        <span class="badge info">À échoir</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($invoices->hasPages())
                    <div class="bd pager">
                        @if ($invoices->previousPageUrl()) <a class="btn ghost sm" href="{{ $invoices->previousPageUrl() }}">← Précédentes</a> @endif
                        <span class="muted">Page {{ $invoices->currentPage() }} sur {{ $invoices->lastPage() }}</span>
                        @if ($invoices->nextPageUrl()) <a class="btn ghost sm" href="{{ $invoices->nextPageUrl() }}">Suivantes →</a> @endif
                    </div>
                @endif
            @endif
        </x-finance::card>

        <x-finance::card title="Ancienneté" hint="{{ $totals['count'] }} créance(s) {{ $type === 'assurances' ? 'assurances' : 'patients' }}">
            <dl class="facts">
                @foreach ($aging as $label => $amount)
                    <div class="f"><dt>{{ $label }}</dt><dd>{{ $money($amount) }}</dd></div>
                @endforeach
                <div class="f gap"><dt>Total</dt><dd>{{ $money(array_sum($aging)) }}</dd></div>
            </dl>
            <p class="muted" style="margin:.75rem 0 0">Depuis la date d'émission de la facture.</p>
        </x-finance::card>
    </div>
@endsection
