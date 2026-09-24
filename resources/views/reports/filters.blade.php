{{-- La même barre de filtres pour les deux rapports et pour les exports. --}}
<x-pharmacie::card title="Période et filtres"
    hint="Du {{ $from->format('d/m/Y') }} au {{ $to->format('d/m/Y') }}">
    <form method="get" action="{{ url()->current() }}">
        <div class="row">
            <label>Du <input type="date" name="du" value="{{ $from->format('Y-m-d') }}"></label>
            <label>Au <input type="date" name="au" value="{{ $to->format('Y-m-d') }}"></label>
            <label>Catégorie
                <select name="categorie">
                    <option value="">Toutes</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected($filters['category_id'] === $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
            </label>
            <label>Emplacement
                <select name="emplacement">
                    <option value="">Tous</option>
                    @foreach ($locations as $location)
                        <option value="{{ $location->id }}" @selected($filters['location_id'] === $location->id)>{{ $location->name }}</option>
                    @endforeach
                </select>
            </label>
        </div>
        <div class="actions">
            <button type="submit"><x-pharmacie::icon name="check" /> Afficher</button>
        </div>
    </form>
</x-pharmacie::card>
