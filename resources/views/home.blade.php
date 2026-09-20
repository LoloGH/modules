@extends('finance::layout')

@section('title', 'Tableau de bord')

@section('content')
    <x-finance::page title="Bonjour !" :sub="$facility.' — vue d\'ensemble de votre activité financière'" />

    @unless ($canReadFigures)
        <x-finance::card>
            <x-finance::empty title="{{ __('finance::messages.home.placeholder') }}" icon="verrou">
                Votre compte accède au module, mais aucun écran ne vous est ouvert pour l'instant.
                Demandez un profil à votre administrateur.
            </x-finance::empty>
        </x-finance::card>
    @else
        {{-- Mesures du jour. Toutes calculées sur les écritures réelles. --}}
        <div class="kpis">
            <x-finance::kpi
                label="Recettes du jour"
                icon="recette"
                tone="green"
                unit="FCFA"
                :value="number_format($metrics['income_today'], 0, ',', ' ')"
                :foot="$metrics['income_trend'] === null
                    ? ($metrics['income_yesterday'] === 0 ? 'Aucune recette hier' : null)
                    : sprintf('%+d %% vs hier', $metrics['income_trend'])" />

            <x-finance::kpi
                label="Dépenses du jour"
                icon="depense"
                tone="red"
                unit="FCFA"
                :value="number_format($metrics['outflow_today'], 0, ',', ' ')"
                :foot="$metrics['outflow_trend'] === null
                    ? ($metrics['outflow_yesterday'] === 0 ? 'Aucune dépense hier' : null)
                    : sprintf('%+d %% vs hier', $metrics['outflow_trend'])" />

            <x-finance::kpi
                label="Encaissements du jour"
                icon="paiement"
                tone="blue"
                :value="$metrics['operations_today']"
                :foot="$metrics['open_sessions'].' session(s) de caisse ouverte(s)'" />

            <x-finance::kpi
                label="Clôtures à valider"
                icon="controle"
                tone="violet"
                :value="$metrics['sessions_to_validate']"
                foot="En attente d'un contrôle" />
        </div>

        <div class="cols wide">
            {{-- Évolution : barres en CSS, aucune bibliothèque de graphiques. --}}
            <x-finance::card title="Évolution des recettes" hint="{{ $window }} derniers jours">
                @if ($metrics['window_total'] === 0)
                    <x-finance::empty title="Aucun encaissement sur la période" icon="recette">
                        Le graphique se remplira dès le premier encaissement enregistré en caisse.
                    </x-finance::empty>
                @else
                    <div class="chart">
                        @foreach ($metrics['series'] as $day)
                            <div class="bar {{ $day['last'] ? 'last' : '' }}">
                                <div class="fill" style="height: {{ max($day['share'], 1) }}%">
                                    @if ($day['last'] && $day['amount'] > 0)
                                        <span class="tip">{{ number_format($day['amount'], 0, ',', ' ') }}</span>
                                    @endif
                                </div>
                                <span class="x">{{ $day['label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-finance::card>

            {{-- Répartition : un anneau SVG, tracé avec stroke-dasharray. --}}
            <x-finance::card title="Répartition des paiements" hint="{{ $window }} derniers jours">
                @if ($metrics['split'] === [])
                    <x-finance::empty title="Aucun paiement sur la période" icon="paiement">
                        La répartition par moyen de paiement apparaîtra ici.
                    </x-finance::empty>
                @else
                    @php $offset = 0; @endphp
                    <div class="donut">
                        <div class="ring">
                            <svg viewBox="0 0 42 42">
                                <circle cx="21" cy="21" r="15.9" fill="none" stroke="var(--line-soft)" stroke-width="6"></circle>
                                @foreach ($metrics['split'] as $part)
                                    <circle cx="21" cy="21" r="15.9" fill="none"
                                            stroke="{{ $part['color'] }}" stroke-width="6"
                                            stroke-dasharray="{{ $part['percent'] }} {{ 100 - $part['percent'] }}"
                                            stroke-dashoffset="{{ 100 - $offset + 25 }}"></circle>
                                    @php $offset += $part['percent']; @endphp
                                @endforeach
                            </svg>
                            <div class="mid">
                                <strong>{{ number_format($metrics['window_total'], 0, ',', ' ') }}</strong>
                                <small>FCFA encaissés</small>
                            </div>
                        </div>
                        <div class="keys">
                            @foreach ($metrics['split'] as $part)
                                <div class="k">
                                    <span class="sw" style="background: {{ $part['color'] }}"></span>
                                    <span class="nm">{{ $part['name'] }}</span>
                                    <span class="pc">{{ $part['percent'] }} %</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </x-finance::card>
        </div>

        <div class="cols wide">
            <x-finance::card title="Dernières transactions" hint="{{ $latest->count() }} dernier(s) encaissement(s)" flush>
                @if ($latest->isEmpty())
                    <div class="bd">
                        <x-finance::empty title="Aucun encaissement enregistré" icon="paiement">
                            Les encaissements saisis en caisse apparaîtront ici.
                        </x-finance::empty>
                    </div>
                @else
                    <div class="tw">
                        <table class="stack">
                            <thead>
                            <tr>
                                <th>N°</th><th>Date</th><th>Description</th>
                                <th class="num">Montant</th><th>Moyen de paiement</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($latest as $payment)
                                <tr>
                                    <td data-l="N°" class="mono">{{ $payment->number }}</td>
                                    <td data-l="Date">{{ $payment->created_at?->format('d/m/Y H:i') }}</td>
                                    <td data-l="Description">
                                        {{ $payment->description ?: 'Encaissement' }}
                                        @if ($payment->patient_name)<span class="sub">{{ $payment->patient_name }}</span>@endif
                                    </td>
                                    <td data-l="Montant" class="num strong">{{ $money($payment->amount) }}</td>
                                    <td data-l="Moyen">{{ $payment->method?->name ?? '—' }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-finance::card>

            {{-- État de la caisse du caissier connecté. --}}
            @can('finance.sessions.view')
                <x-finance::card>
                    <x-slot:title>État de la caisse</x-slot:title>
                    <x-slot:actions>
                        @if ($session)
                            <span class="badge open"><span class="pt"></span>Ouverte</span>
                        @else
                            <span class="badge off">Fermée</span>
                        @endif
                    </x-slot:actions>

                    @if ($session)
                        <dl class="facts">
                            <div class="f"><dt>Caisse</dt><dd>{{ $session->register->name }}</dd></div>
                            <div class="f"><dt>Caissier</dt><dd>{{ $session->cashier_name }}</dd></div>
                            <div class="f"><dt>Ouverture</dt><dd>{{ $session->opened_at?->format('d/m/Y H:i') }}</dd></div>
                            <div class="f"><dt>Fonds initial</dt><dd>{{ $money($totals['opening_float']) }}</dd></div>
                            <div class="f"><dt>Total encaissements</dt><dd>{{ $money($totals['cash_in']) }}</dd></div>
                            <div class="f"><dt>Total décaissements</dt><dd>{{ $money($totals['cash_out']) }}</dd></div>
                            <div class="f total"><dt>Solde théorique</dt><dd>{{ $money($totals['expected_cash']) }}</dd></div>
                        </dl>

                        <a class="btn block lg" style="margin-top:1rem" href="{{ route('finance.cash.sessions.show', $session) }}">
                            <x-finance::icon name="caisse" /> Ma caisse <x-finance::icon name="fleche" />
                        </a>
                    @else
                        <x-finance::empty title="Aucune session ouverte" icon="caisse">
                            Ouvrez votre session pour commencer à encaisser.
                        </x-finance::empty>

                        <a class="btn block lg" href="{{ route('finance.cash.index') }}">
                            <x-finance::icon name="caisse" /> Ma caisse <x-finance::icon name="fleche" />
                        </a>
                    @endif
                </x-finance::card>
            @endcan
        </div>
    @endunless
@endsection
