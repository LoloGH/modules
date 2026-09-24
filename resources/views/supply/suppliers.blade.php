@extends('pharmacie::layout')

@section('title', 'Fournisseurs')

@section('content')
    <x-pharmacie::page title="Fournisseurs"
        sub="Qui nous livre, à quelles conditions, et tout ce qui est déjà venu de chez lui." />

    @can('pharmacie.stock.receive')
        <x-pharmacie::card title="Nouveau fournisseur">
            <form method="post" action="{{ route('pharmacie.supply.suppliers.store') }}">
                @csrf
                <div class="row">
                    <label>Code <input name="code" value="{{ old('code') }}" placeholder="UBIPHARM" required></label>
                    <label>Nom <input name="name" value="{{ old('name') }}" placeholder="Ubipharm Mali" required></label>
                    <label>Contact <input name="contact_name" value="{{ old('contact_name') }}"></label>
                </div>
                <div class="row">
                    <label>Téléphone <input name="phone" value="{{ old('phone') }}" placeholder="+223 ..."></label>
                    <label>E-mail <input name="email" value="{{ old('email') }}"></label>
                    <label>Adresse <input name="address" value="{{ old('address') }}"></label>
                </div>
                <div class="row">
                    <label>Délai de paiement (jours) <input name="payment_days" inputmode="numeric" value="{{ old('payment_days') }}"></label>
                    <label>Délai de livraison (jours) <input name="lead_time_days" inputmode="numeric" value="{{ old('lead_time_days') }}"></label>
                    <label>Observations <input name="notes" value="{{ old('notes') }}"></label>
                </div>
                <div class="actions">
                    <button type="submit"><x-pharmacie::icon name="plus" /> Créer le fournisseur</button>
                </div>
            </form>
        </x-pharmacie::card>
    @endcan

    <x-pharmacie::card title="Fournisseurs de la pharmacie" hint="{{ $suppliers->count() }} fournisseur(s)" flush>
        @if ($suppliers->isEmpty())
            <div class="bd">
                <x-pharmacie::empty title="Aucun fournisseur" icon="reception">
                    Créez-en un : une réception vient toujours de quelqu'un.
                </x-pharmacie::empty>
            </div>
        @else
            <div class="tw">
                <table class="stack wide">
                    <thead><tr><th>Fournisseur</th><th>Contact</th><th class="num">Commandes</th><th class="num">Réceptions</th><th>État</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($suppliers as $supplier)
                        <tr>
                            <td data-l="Fournisseur" class="strong">
                                {{ $supplier->name }}
                                <span class="sub mono">{{ $supplier->code }}</span>
                            </td>
                            <td data-l="Contact">
                                {{ $supplier->contact_name ?? '-' }}
                                @if ($supplier->phone) <span class="sub">{{ $supplier->phone }}</span> @endif
                            </td>
                            <td data-l="Commandes" class="num">{{ $supplier->orders_count }}</td>
                            <td data-l="Réceptions" class="num">{{ $supplier->receptions_count }}</td>
                            <td data-l="État"><span class="badge {{ $supplier->is_active ? 'ok' : 'off' }}">{{ $supplier->is_active ? 'Actif' : 'Désactivé' }}</span></td>
                            <td data-l="" class="acts">
                                <a class="btn ghost sm" href="{{ route('pharmacie.supply.suppliers.show', $supplier) }}">Ouvrir</a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-pharmacie::card>
@endsection
