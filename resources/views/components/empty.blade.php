{{-- État vide : ce qui manque, et quoi faire ensuite. --}}
@props(['title', 'icon' => 'vide'])

<div class="empty">
    <x-pharmacie::icon :name="$icon" />
    <p>{{ $title }}</p>
    @if (trim($slot) !== '') <small>{{ $slot }}</small> @endif
</div>
