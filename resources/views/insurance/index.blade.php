@extends('finance::layout')

@section('title', 'Assurances')

@section('content')
    <x-finance::page title="Assurances" sub="Prises en charge, part assurance et part patient, règlements, rejets et créances des assureurs." />

    <div class="kpis">
        <x-finance::kpi label="Part assurance" icon="assurance" tone="blue" :value="$money($stats['share'])" foot="Factures prises en charge" />
        <x-finance::kpi label="Réglé par les assureurs" icon="recette" tone="green" :value="$money($stats['paid'])" />
        <x-finance::kpi label="Rejeté" icon="alert" tone="amber" :value="$money($stats['rejected'])" foot="À la charge des patients" />
        <x-finance::kpi label="Créance assurance" icon="creance" tone="red" :value="$money($stats['outstanding'])" foot="Reste dû par les assureurs" />
    </div>

    <x-finance::card title="Prises en charge" flush>
        <div class="bd">
            <form method="get" action="{{ route('finance.insurance.index') }}">
                <div class="row">
                    <label>Assureur
                        <select name="assureur">
                            <option value="">Tous</option>
                            @foreach ($insurers as $insurer)
                                <option value="{{ $insurer->id }}" @selected($insurerId === $insurer->id)>{{ $insurer->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Statut de la créance
                        <select name="statut">
                            <option value="">Tous</option>
                            @foreach (\Keneya\FinanceCaisse\Models\Invoice::claimLabels() as $code => $label)
                                <option value="{{ $code }}" @selected($claim === $code)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Recherche <input name="q" value="{{ $search }}" placeholder="Facture, patient, n° de prise en charge…"></label>
                </div>
                <div class="actions">
                    <button type="submit" class="sm">Filtrer</button>
                    <a class="btn ghost sm" href="{{ route('finance.insurance.index') }}">Réinitialiser</a>
                </div>
            </form>
        </div>

        @if ($invoices->isEmpty())
            <div class="bd">
                <x-finance::empty title="Aucune prise en charge" icon="assurance">
                    Une facture prise en charge par un assureur apparaît ici, avec sa part assurance et ce qu'il en reste dû.
                </x-finance::empty>
            </div>
        @else
            <div class="tw">
                <table class="stack wide">
                    <thead>
                    <tr>
                        <th>Facture</th><th>Patient</th><th>Assureur</th>
                        <th class="num">Montant</th><th class="num">Part assurance</th><th class="num">Part patient</th>
                        <th class="num">Réglé</th><th class="num">Rejeté</th><th class="num">Reste dû</th><th>Statut</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($invoices as $invoice)
                        <tr>
                            <td data-l="Facture" class="mono">
                                <a href="{{ route('finance.invoices.show', $invoice) }}">{{ $invoice->number }}</a>
                                <span class="sub">{{ $invoice->created_at?->format('d/m/Y') }}</span>
                            </td>
                            <td data-l="Patient" class="strong">{{ $invoice->patient_name ?? '—' }}
                                @if ($invoice->patient_id) <span class="sub mono">{{ $invoice->patient_id }}</span> @endif</td>
                            <td data-l="Assureur">{{ $invoice->insurer?->name }}
                                <span class="sub">{{ $invoice->coverage_rate }} %@if ($invoice->policy_number) · PEC {{ $invoice->policy_number }}@endif</span></td>
                            <td data-l="Montant" class="num">{{ $money($invoice->total) }}</td>
                            <td data-l="Part assurance" class="num strong">{{ $money($invoice->insurer_share) }}</td>
                            <td data-l="Part patient" class="num">{{ $money($invoice->patient_share) }}</td>
                            <td data-l="Réglé" class="num">{{ $money($invoice->insurer_paid) }}</td>
                            <td data-l="Rejeté" class="num">{{ $money($invoice->insurer_rejected) }}</td>
                            <td data-l="Reste dû" class="num strong">{{ $money($invoice->insurerOutstanding()) }}</td>
                            <td data-l="Statut"><span class="badge {{ $invoice->claimTone() }}">{{ $invoice->claimLabel() }}</span></td>
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

    <div class="cols">
        <x-finance::card title="Derniers règlements" flush>
            @if ($settlements->isEmpty())
                <div class="bd muted">Aucun règlement d'assureur enregistré.</div>
            @else
                <div class="tw"><table class="stack">
                    <thead><tr><th>N°</th><th>Assureur</th><th>Facture</th><th>Reçu le</th><th class="num">Montant</th></tr></thead>
                    <tbody>
                    @foreach ($settlements as $settlement)
                        <tr>
                            <td data-l="N°" class="mono">{{ $settlement->number }}@if ($settlement->reference) <span class="sub">{{ $settlement->reference }}</span>@endif</td>
                            <td data-l="Assureur">{{ $settlement->insurer?->name }}</td>
                            <td data-l="Facture"><a href="{{ route('finance.invoices.show', $settlement->invoice_id) }}">{{ $settlement->invoice?->number }}</a></td>
                            <td data-l="Reçu le">{{ $settlement->received_on?->format('d/m/Y') }}</td>
                            <td data-l="Montant" class="num strong">{{ $money($settlement->amount) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            @endif
        </x-finance::card>

        <x-finance::card title="Derniers rejets" flush>
            @if ($rejections->isEmpty())
                <div class="bd muted">Aucun rejet enregistré.</div>
            @else
                <div class="tw"><table class="stack">
                    <thead><tr><th>Assureur</th><th>Facture</th><th>Motif</th><th class="num">Montant</th></tr></thead>
                    <tbody>
                    @foreach ($rejections as $rejection)
                        <tr>
                            <td data-l="Assureur">{{ $rejection->insurer?->name }}</td>
                            <td data-l="Facture"><a href="{{ route('finance.invoices.show', $rejection->invoice_id) }}">{{ $rejection->invoice?->number }}</a></td>
                            <td data-l="Motif">{{ $rejection->reason }}</td>
                            <td data-l="Montant" class="num strong">{{ $money($rejection->amount) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            @endif
        </x-finance::card>
    </div>

    <div class="cols wide">
        <x-finance::card title="Assureurs" hint="{{ $insurers->count() }} assureur(s)" flush>
            @if ($insurers->isEmpty())
                <div class="bd">
                    <x-finance::empty title="Aucun assureur" icon="assurance">Créez les assureurs et tiers payants avec lesquels l'établissement travaille.</x-finance::empty>
                </div>
            @else
                <div class="tw"><table class="stack">
                    <thead><tr><th>Code</th><th>Nom</th><th class="num">Taux par défaut</th><th class="num">Factures</th><th>État</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($insurers as $insurer)
                        <tr>
                            <td data-l="Code" class="mono">{{ $insurer->code }}</td>
                            <td data-l="Nom" class="strong">{{ $insurer->name }}
                                @if ($insurer->phone || $insurer->email) <span class="sub">{{ collect([$insurer->phone, $insurer->email])->filter()->implode(' · ') }}</span> @endif</td>
                            <td data-l="Taux par défaut" class="num">{{ $insurer->default_rate }} %</td>
                            <td data-l="Factures" class="num">{{ $insurer->invoices_count }}</td>
                            <td data-l="État"><span class="badge {{ $insurer->is_active ? 'ok' : 'off' }}">{{ $insurer->is_active ? 'Actif' : 'Désactivé' }}</span></td>
                            <td data-l="" class="acts">
                                @can('finance.insurance.manage')
                                    <form method="post" action="{{ route('finance.insurers.toggle', $insurer) }}">@csrf
                                        <button type="submit" class="ghost sm">{{ $insurer->is_active ? 'Désactiver' : 'Activer' }}</button>
                                    </form>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            @endif
        </x-finance::card>

        @can('finance.insurance.manage')
            <x-finance::card title="Nouvel assureur">
                <form method="post" action="{{ route('finance.insurers.store') }}">
                    @csrf
                    <label>Code <input name="code" value="{{ old('code') }}" placeholder="INPS" required></label>
                    <label>Nom <input name="name" value="{{ old('name') }}" placeholder="Institut national de prévoyance sociale" required></label>
                    <label>Taux de prise en charge par défaut
                        <input name="default_rate" inputmode="numeric" value="{{ old('default_rate', 80) }}" required>
                        <span class="help">En %, proposé à chaque prise en charge et modifiable.</span>
                    </label>
                    <label>Téléphone <input name="phone" value="{{ old('phone') }}"></label>
                    <label>E-mail <input name="email" value="{{ old('email') }}"></label>
                    <div class="actions"><button type="submit"><x-finance::icon name="plus" /> Créer l'assureur</button></div>
                </form>
            </x-finance::card>
        @endcan
    </div>
@endsection
