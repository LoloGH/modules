@extends('pharmacie::layout')

@section('title', 'Tableau de bord')

@section('content')
    <x-pharmacie::page title="Bonjour !" :sub="$facility.' — pharmacie'" />

    <div class="kpis">
        <x-pharmacie::kpi label="Patients en attente" icon="horloge" tone="blue" :value="(string) $waiting"
                          :foot="count($queues).' file(s) fournie(s) par l\'application hôte'" />
        <x-pharmacie::kpi label="Produits en stock" icon="produit" tone="green" value="—" foot="Tranche à venir" />
        <x-pharmacie::kpi label="Lots proches de la péremption" icon="alert" tone="amber" value="—" foot="Tranche à venir" />
        <x-pharmacie::kpi label="Dispensations du jour" icon="dispensation" tone="violet" value="—" foot="Tranche à venir" />
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

    <x-pharmacie::card title="Ce que ce module fera" hint="Feuille de route">
        <dl class="facts">
            <div class="f"><dt>File d'attente</dt><dd>Fournie par l'hôte, comme la file de caisse de Finance</dd></div>
            <div class="f"><dt>Catalogue des produits</dt><dd>Médicaments et consommables, avec leur prix de vente</dd></div>
            <div class="f"><dt>Stock par lot</dt><dd>Entrées, sorties, péremptions, inventaires</dd></div>
            <div class="f"><dt>Dispensation</dt><dd>Ce qui est délivré, à qui, sur quelle ordonnance</dd></div>
            <div class="f gap"><dt>Encaissement</dt><dd>Envoyé à la caisse par un contrat neutre (<code>Contracts\SaleSink</code>)</dd></div>
        </dl>
    </x-pharmacie::card>
@endsection
