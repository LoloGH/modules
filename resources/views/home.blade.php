@extends('finance::layout')

@section('title', 'Tableau de bord')

@section('content')
    <x-finance::page title="Bonjour !" :sub="$facility.' · vue d\'ensemble de votre activité financière'" />

    @unless ($canReadFigures)
        <x-finance::card>
            <x-finance::empty title="{{ __('finance::messages.home.placeholder') }}" icon="verrou">
                Votre compte accède au module, mais aucun écran ne vous est ouvert pour l'instant.
                Demandez un profil à votre administrateur.
            </x-finance::empty>
        </x-finance::card>
    @else
        @php $period = $metrics['period']; @endphp

        {{-- La période regardée. Tout l'écran la suit, comparaison comprise. --}}
        <div class="switch">
            <span class="lbl">Période :</span>
            @foreach (\Keneya\FinanceCaisse\Support\DashboardPeriod::CHOICES as $key => $label)
                <a class="btn ghost sm {{ $period->key === $key ? 'on' : '' }}"
                   href="{{ route('finance.home', $key === \Keneya\FinanceCaisse\Support\DashboardPeriod::DEFAULT ? [] : ['periode' => $key]) }}">{{ $label }}</a>
            @endforeach
        </div>

        {{-- Mesures de la période. Toutes calculées sur les écritures réelles. --}}
        <div class="kpis">
            <x-finance::kpi
                label="Recettes {{ $period->label }}"
                icon="recette"
                tone="green"
                unit="FCFA"
                :value="number_format($metrics['revenue'], 0, ',', ' ')"
                :foot="$metrics['revenue_trend'] === null
                    ? ($metrics['revenue_before'] === 0 ? 'Aucune recette '.$period->comparison : null)
                    : sprintf('%+d %% vs %s', $metrics['revenue_trend'], $period->comparison)" />

            <x-finance::kpi
                label="Dépenses {{ $period->label }}"
                icon="depense"
                tone="red"
                unit="FCFA"
                :value="number_format($metrics['expenses'], 0, ',', ' ')"
                :foot="$metrics['expenses_trend'] === null
                    ? ($metrics['expenses_before'] === 0 ? 'Aucune dépense '.$period->comparison : null)
                    : sprintf('%+d %% vs %s', $metrics['expenses_trend'], $period->comparison)" />

            <x-finance::kpi
                label="Résultat {{ $period->label }}"
                icon="rapport"
                tone="violet"
                unit="FCFA"
                :value="number_format($metrics['net'], 0, ',', ' ')"
                :foot="$metrics['settlements'] > 0
                    ? 'Dont '.$money($metrics['settlements']).' réglés par les assureurs'
                    : 'Recettes moins dépenses'" />

            <x-finance::kpi
                label="Encaissements {{ $period->label }}"
                icon="paiement"
                tone="blue"
                :value="$metrics['operations']"
                :foot="$metrics['open_sessions'].' session(s) de caisse ouverte(s)'" />
        </div>

        @php $todo = $metrics['todo']; @endphp
        @if (array_sum($todo) > 0)
            <x-finance::card title="À traiter" hint="Ce qui attend une décision ou un geste">
                <div class="switch">
                    @can('finance.sessions.validate')
                        @if ($todo['sessions'] > 0)
                            <a class="btn ghost sm" href="{{ route('finance.review.index') }}">
                                <x-finance::icon name="controle" /> {{ $todo['sessions'] }} clôture(s) à valider
                            </a>
                        @endif
                    @endcan
                    @can('finance.credits.view')
                        @if ($todo['discounts'] > 0)
                            <a class="btn ghost sm" href="{{ route('finance.credits.index') }}">
                                <x-finance::icon name="avoir" /> {{ $todo['discounts'] }} remise(s) à approuver
                            </a>
                        @endif
                        @if ($todo['refunds'] > 0)
                            <a class="btn ghost sm" href="{{ route('finance.credits.index') }}">
                                <x-finance::icon name="avoir" /> {{ $todo['refunds'] }} remboursement(s) à approuver
                            </a>
                        @endif
                        @if ($todo['refunds_to_pay'] > 0)
                            <a class="btn ghost sm" href="{{ route('finance.cash.index') }}">
                                <x-finance::icon name="depense" /> {{ $todo['refunds_to_pay'] }} remboursement(s) à payer
                            </a>
                        @endif
                    @endcan
                </div>
            </x-finance::card>
        @endif

        <div class="cols wide">
            {{-- Évolution : barres en CSS, aucune bibliothèque de graphiques. --}}
            <x-finance::card title="Évolution des recettes" hint="{{ $period->seriesDays }} derniers jours">
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
            <x-finance::card title="Répartition des paiements" hint="Recettes {{ $period->label }}">
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
                                <strong>{{ number_format($metrics['revenue'], 0, ',', ' ') }}</strong>
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

        <div class="cols">
            {{-- D'où vient l'argent : le centre gravé sur chaque encaissement. --}}
            <x-finance::card title="Recettes par service" hint="Centres analytiques {{ $period->label }}">
                @if ($metrics['centers'] === [])
                    <p class="muted" style="margin:0">Aucune recette sur la période.</p>
                @else
                    <div class="ranks">
                        @foreach ($metrics['centers'] as $center)
                            <div class="rank">
                                <div class="rank-head">
                                    <span class="nm">{{ $center['name'] }}</span>
                                    <span class="amt">{{ $money($center['amount']) }}</span>
                                </div>
                                <div class="track"><div class="fill" style="width: {{ max($center['percent'], 2) }}%"></div></div>
                                <span class="sub">{{ $center['percent'] }} % des recettes</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-finance::card>

            <x-finance::card title="Actes les plus encaissés" hint="{{ count($metrics['acts']) }} sur la période" flush>
                @if ($metrics['acts'] === [])
                    <div class="bd">
                        <p class="muted" style="margin:0">Aucun acte encaissé sur la période.</p>
                    </div>
                @else
                    <div class="tw">
                        <table class="stack">
                            <thead><tr><th>Acte</th><th class="num">Fois</th><th class="num">Montant</th></tr></thead>
                            <tbody>
                            @foreach ($metrics['acts'] as $act)
                                <tr>
                                    <td data-l="Acte" class="strong">{{ $act['name'] }}</td>
                                    <td data-l="Fois" class="num">{{ $act['count'] }}</td>
                                    <td data-l="Montant" class="num strong">{{ $money($act['amount']) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-finance::card>
        </div>

        @php $dues = $metrics['dues']; @endphp
        @php $coverage = $metrics['coverage']; @endphp

        <div class="cols">
            @if (array_sum($dues) > 0)
                <x-finance::card title="Ce qui reste dû" hint="Situation du jour, toutes périodes confondues">
                    <dl class="facts">
                        @can('finance.receivables.view')
                            <div class="f"><dt>Créances des patients</dt><dd>{{ $money($dues['patients']) }}</dd></div>
                            <div class="f"><dt>Créances des assureurs</dt><dd>{{ $money($dues['insurers']) }}</dd></div>
                        @endcan
                        @can('finance.accounts.view')
                            <div class="f"><dt>Avances dues aux patients</dt><dd>{{ $money($dues['accounts']) }}</dd></div>
                        @endcan
                        @can('finance.credits.view')
                            <div class="f gap"><dt>Remboursements approuvés à payer</dt><dd>{{ $money($dues['refunds']) }}</dd></div>
                        @endcan
                    </dl>
                </x-finance::card>
            @endif

            @if (array_sum($coverage) > 0)
                <x-finance::card title="Prises en charge {{ $period->label }}" hint="Assurances et aides sociales">
                    <dl class="facts">
                        <div class="f"><dt>Part des assurances</dt><dd>{{ $money($coverage['insurance']) }}</dd></div>
                        <div class="f"><dt>Part des aides sociales</dt><dd>{{ $money($coverage['social_aid']) }}</dd></div>
                        <div class="f"><dt>Réglé par les organismes</dt><dd>{{ $money($coverage['paid']) }}</dd></div>
                        <div class="f gap"><dt>Reste dû par les organismes</dt><dd>{{ $money($coverage['outstanding']) }}</dd></div>
                    </dl>
                </x-finance::card>
            @endif
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
                                    <td data-l="Moyen">{{ $payment->method?->name ?? '-' }}</td>
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
