@extends('finance::layout')

@section('title', 'Centres analytiques')

@section('content')
    <x-finance::page
        title="Centres analytiques"
        sub="Ils disent d'où vient l'argent : combien rapporte le laboratoire, l'imagerie, la maternité." />

    @can('finance.catalog.manage')
        <x-finance::card title="Nouveau centre">
            <form method="post" action="{{ route('finance.catalog.centers.store') }}">
                @csrf
                <div class="row">
                    <label>Code <input name="code" value="{{ old('code') }}" placeholder="LABORATOIRE" required></label>
                    <label>Nom <input name="name" value="{{ old('name') }}" placeholder="Laboratoire" required></label>
                </div>
                <div class="row">
                    <label>Rattaché à
                        <select name="parent_id">
                            <option value="">— Aucun (centre racine)</option>
                            @foreach ($parents as $parent)
                                <option value="{{ $parent->id }}" @selected((string) old('parent_id') === (string) $parent->id)>{{ $parent->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Type
                        <select name="kind" required>
                            @foreach ($kinds as $key => $label)
                                <option value="{{ $key }}" @selected(old('kind', 'revenue') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Compte de produits
                        <input name="account_code" value="{{ old('account_code') }}" placeholder="706">
                        <span class="help">Pour l'export comptable. Vide : le compte par défaut.</span>
                    </label>
                </div>
                <div class="actions">
                    <button type="submit"><x-finance::icon name="plus" /> Créer le centre</button>
                </div>
            </form>
        </x-finance::card>
    @endcan

    @can('finance.catalog.manage')
        <x-finance::card title="Modifier un centre" hint="Le code ne change pas : les rapports passés s'y réfèrent">
            @if ($centers->isEmpty())
                <p class="muted" style="margin:0">Aucun centre à modifier.</p>
            @else
                @foreach ($tree as $row)
                    @php ($center = $row['center'])
                    <details class="center-edit">
                        <summary>{{ $center->name }} <span class="muted">· {{ $center->code }} · {{ $center->kindLabel() }}</span></summary>
                        <form method="post" action="{{ route('finance.catalog.centers.update', $center) }}">
                            @csrf
                            <div class="row">
                                <label>Nom <input name="name" value="{{ $center->name }}" required></label>
                                <label>Rattaché à
                                    <select name="parent_id">
                                        <option value="">— Aucun (centre racine)</option>
                                        @foreach ($parents as $parent)
                                            @continue($parent->id === $center->id)
                                            <option value="{{ $parent->id }}" @selected($center->parent_id === $parent->id)>{{ $parent->name }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label>Type
                                    <select name="kind" required>
                                        @foreach ($kinds as $key => $label)
                                            <option value="{{ $key }}" @selected($center->kind === $key)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label>Compte de produits
                                    <input name="account_code" value="{{ $center->account_code }}" placeholder="706">
                                </label>
                            </div>
                            <div class="actions">
                                <button type="submit" class="ghost sm"><x-finance::icon name="check" /> Enregistrer</button>
                            </div>
                        </form>
                    </details>
                @endforeach
            @endif
        </x-finance::card>
    @endcan

    <x-finance::card title="Centres de l'établissement" hint="{{ count($tree) }} centre(s)" flush>
        @if ($tree === [])
            <div class="bd">
                <x-finance::empty title="Aucun centre analytique" icon="centre">
                    La commande finance:sync-catalog crée ceux de départ.
                </x-finance::empty>
            </div>
        @else
            <div class="tw">
                <table class="stack">
                    <thead>
                    <tr><th>Nom</th><th>Code</th><th>Type</th><th>Compte</th><th class="num">Actes</th><th>État</th>@can('finance.catalog.manage')<th></th>@endcan</tr>
                    </thead>
                    <tbody>
                    @foreach ($tree as $row)
                        @php ($center = $row['center'])
                        <tr>
                            <td data-l="Nom" class="strong">
                                @if ($row['depth'] > 0)
                                    <span class="tree-in">{!! str_repeat('&nbsp;&nbsp;&nbsp;', $row['depth']) !!}└&nbsp;</span>
                                @endif
                                {{ $center->name }}
                            </td>
                            <td data-l="Code" class="mono">{{ $center->code }}</td>
                            <td data-l="Type">{{ $center->kindLabel() }}</td>
                            <td data-l="Compte" class="mono">{{ $center->account_code ?? '—' }}</td>
                            <td data-l="Actes" class="num">{{ $center->acts_count }}</td>
                            <td data-l="État">
                                <span class="badge {{ $center->is_active ? 'ok' : 'off' }}">{{ $center->is_active ? 'Actif' : 'Désactivé' }}</span>
                            </td>
                            @can('finance.catalog.manage')
                                <td data-l="" class="acts">
                                    <form method="post" action="{{ route('finance.catalog.centers.toggle', $center) }}">@csrf
                                        <button type="submit" class="ghost sm">{{ $center->is_active ? 'Désactiver' : 'Activer' }}</button>
                                    </form>
                                </td>
                            @endcan
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-finance::card>
@endsection
