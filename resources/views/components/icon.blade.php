{{--
    Jeu d'icônes du module, en SVG dans la page.

    Volontairement pas de bibliothèque d'icônes : le module se monte chez un
    hôte et ne doit ajouter aucune dépendance. Toutes les icônes partagent la
    même grille 24, le même trait, et héritent de la couleur du texte.

    Usage : <x-finance::icon name="caisse" />
--}}
@props(['name' => 'point'])

@php
    $paths = [
        'dashboard' => '<path d="M4 13h6V4H4zM14 20h6v-9h-6zM4 20h6v-4H4zM14 8h6V4h-6z"/>',
        'caisse' => '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M7 7V5a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v2M7 12h4M7 16h10"/>',
        'facture' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h4"/>',
        'paiement' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h4"/>',
        'recette' => '<path d="M12 19V5M5 12l7-7 7 7"/>',
        'depense' => '<path d="M12 5v14M19 12l-7 7-7-7"/>',
        'assurance' => '<path d="M12 3l8 3v6c0 4.4-3.2 7.9-8 9-4.8-1.1-8-4.6-8-9V6z"/>',
        'creance' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9"/>',
        'rapport' => '<path d="M3 3v18h18"/><rect x="7" y="12" width="3" height="6" rx="1"/><rect x="12" y="8" width="3" height="10" rx="1"/><rect x="17" y="4" width="3" height="14" rx="1"/>',
        'reglage' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-1.8-.3 1.6 1.6 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1A1.6 1.6 0 0 0 9 19.4a1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0 .3-1.8 1.6 1.6 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1A1.6 1.6 0 0 0 4.6 9a1.6 1.6 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 1.8.3H9a1.6 1.6 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.6 1.6 0 0 0 1 1.5 1.6 1.6 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8V9a1.6 1.6 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1z"/>',
        'acte' => '<path d="M20.6 13.4 12 22l-9-9V3h10z"/><circle cx="7.5" cy="7.5" r="1.2"/>',
        'centre' => '<rect x="9" y="3" width="6" height="5" rx="1"/><rect x="2" y="16" width="6" height="5" rx="1"/><rect x="16" y="16" width="6" height="5" rx="1"/><path d="M12 8v4M5 16v-2h14v2"/>',
        'controle' => '<path d="M9 11l2.5 2.5L16 9"/><path d="M21 12a9 9 0 1 1-9-9"/><path d="M15 3h6v6"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'alert' => '<circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5v.01"/>',
        'bell' => '<path d="M18 8a6 6 0 1 0-12 0c0 7-3 8-3 8h18s-3-1-3-8"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'calendrier' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
        'chevron' => '<path d="M6 9l6 6 6-6"/>',
        'fleche' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'retour' => '<path d="M19 12H5M11 18l-6-6 6-6"/>',
        'vide' => '<path d="M4 7h16v13H4z"/><path d="M4 7l2-3h12l2 3M9 12h6"/>',
        'horloge' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'verrou' => '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 1 1 8 0v3"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'point' => '<circle cx="12" cy="12" r="3"/>',
    ];
@endphp

<svg {{ $attributes->merge(['class' => 'ic']) }} viewBox="0 0 24 24" aria-hidden="true" focusable="false">{!! $paths[$name] ?? $paths['point'] !!}</svg>
