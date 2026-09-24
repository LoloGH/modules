@extends('pharmacie::layout')

@section('title', 'Tableau de bord')

@section('content')
    <x-pharmacie::page title="Bonjour !" :sub="$facility.' · pharmacie'">
        @if ($canReport)
            <x-slot:actions>
                <a class="btn ghost sm" href="{{ route('pharmacie.reports.index') }}">
                    <x-pharmacie::icon name="rapport" /> Rapports
                </a>
            </x-slot:actions>
        @endif
    </x-pharmacie::page>

    <div class="kpis">
        <x-pharmacie::kpi label="Patients en attente" icon="horloge" tone="blue" :value="(string) $waiting"
                          :foot="count($queues).' file(s) fournie(s) par l\'application hôte'" />

        @if ($figures)
            <x-pharmacie::kpi label="Produits en stock" icon="produit" tone="green"
                              :value="(string) $figures['products_in_stock']"
                              :foot="$money($figures['stock_value']).' immobilisés'" />
            <x-pharmacie::kpi label="Lots proches de la péremption" icon="alert" tone="amber"
                              :value="(string) $figures['expiring']"
                              :foot="'Dans moins de '.config('pharmacie.stock.expiry_warning_days', 90).' jours'" />
            <x-pharmacie::kpi label="Dispensations du jour" icon="dispensation" tone="violet"
                              :value="(string) $figures['dispensations_today']"
                              :foot="$money($figures['dispensed_value_today']).' délivrés'" />
        @else
            <x-pharmacie::kpi label="Stock" icon="produit" tone="green" value="-"
                              foot="Vous n'avez pas le droit de consulter le stock" />
        @endif
    </div>

    @unless ($queueConnected)
        <x-pharmacie::card title="Montage du module">
            <p class="muted" style="margin:0">
                L'application hôte ne fournit pas encore de file d'attente : la pharmacie
                n'invente pas de patients. Branchez une implémentation de
                <code>Contracts\PharmacyQueueProvider</code> pour que le comptoir voie qui attend.
            </p>
        </x-pharmacie::card>
    @endunless

    <x-pharmacie::card title="Ce que ce module fait" hint="D'un bout à l'autre de la vie du médicament">
        <dl class="facts">
            <div class="f"><dt>File d'attente</dt><dd>Fournie par l'hôte, comme la file de caisse de Finance</dd></div>
            <div class="f"><dt>Catalogue et stock par lot</dt><dd>Entrées, sorties, péremptions, transferts, inventaires</dd></div>
            <div class="f"><dt>Dispensation</dt><dd>FEFO d'office, dérogation justifiée, reliquat visible</dd></div>
            <div class="f"><dt>Surveillance</dt><dd>Registre sous contrôle, rappels de lots, pharmacovigilance</dd></div>
            <div class="f gap"><dt>Encaissement</dt><dd>Envoyé à la caisse par un contrat neutre (<code>Contracts\SaleSink</code>)</dd></div>
        </dl>
    </x-pharmacie::card>
@endsection
