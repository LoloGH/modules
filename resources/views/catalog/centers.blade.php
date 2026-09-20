@extends('finance::layout')

@section('title', 'Centres analytiques')

@section('content')
    <h1>Centres analytiques</h1>
    <p class="muted">Ils disent d'où vient l'argent : combien rapporte le laboratoire, l'imagerie, la maternité.</p>

    @can('finance.catalog.manage')
        <section class="card">
            <h2>Nouveau centre</h2>
            <form method="post" action="{{ route('finance.catalog.centers.store') }}">
                @csrf
                <label>Code (ex. LABORATOIRE) <input name="code" value="{{ old('code') }}" required></label>
                <label>Nom (ex. Laboratoire) <input name="name" value="{{ old('name') }}" required></label>
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
                <button type="submit">Créer le centre</button>
            </form>
        </section>
    @endcan

    <section class="card">
        <h2>Centres de l'établissement</h2>
        @if ($tree === [])
            <p class="muted">Aucun centre analytique. La commande <code>finance:sync-catalog</code> crée ceux de départ.</p>
        @else
            <table>
                <thead><tr><th>Nom</th><th>Code</th><th>Type</th><th class="num">Actes</th><th>État</th>@can('finance.catalog.manage')<th></th>@endcan</tr></thead>
                <tbody>
                @foreach ($tree as $row)
                    @php ($center = $row['center'])
                    <tr>
                        <td>{!! str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $row['depth']) !!}{{ $row['depth'] > 0 ? '└ ' : '' }}{{ $center->name }}</td>
                        <td>{{ $center->code }}</td>
                        <td>{{ $center->kindLabel() }}</td>
                        <td class="num">{{ $center->acts_count }}</td>
                        <td>{{ $center->is_active ? 'Actif' : 'Désactivé' }}</td>
                        @can('finance.catalog.manage')
                            <td>
                                <form method="post" action="{{ route('finance.catalog.centers.toggle', $center) }}">@csrf
                                    <button type="submit" class="secondary">{{ $center->is_active ? 'Désactiver' : 'Activer' }}</button>
                                </form>
                            </td>
                        @endcan
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </section>
@endsection
