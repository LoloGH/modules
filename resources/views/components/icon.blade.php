{{--
    Jeu d'icônes du module, en SVG dans la page.

    Volontairement pas de bibliothèque d'icônes : le module se monte chez un
    hôte et ne doit ajouter aucune dépendance. Toutes les icônes partagent la
    même grille 24, le même trait, et héritent de la couleur du texte.

    Usage : <x-pharmacie::icon name="caisse" />
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
        'produit' => '<rect x="3" y="8" width="18" height="12" rx="2"/><path d="M3 12h18M8 8V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v3"/>',
        'lot' => '<path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="M3 8l9 5 9-5M12 13v8"/>',
        'reception' => '<path d="M12 3v12M7 10l5 5 5-5"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/>',
        'inventaire' => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M9 8h6M9 12h6M9 16h3"/>',
        'dispensation' => '<path d="M10.5 3.5a4.95 4.95 0 0 1 7 7l-7 7a4.95 4.95 0 0 1-7-7z"/><path d="M7 7l7 7"/>',
        'ordonnance' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 12h3a1.5 1.5 0 0 1 0 3H9v-3zm0 3 3.5 3.5"/>',
        'utilisateur' => '<circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v1"/>',
        // Un registre, et non le trace de `facture` : les deux etaient
        // identiques, et le menu montrait la meme image pour deux choses.
        'document' => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 3v18M11 8h6M11 12h6M11 16h4"/>',
        // Huit icones nees d'un meme constat : le comptoir montrait la meme
        // image que les dispensations, les commandes la meme que les
        // ordonnances, et l'alerte servait pour les peremptions, les pertes
        // et les rappels. Un menu de vingt entrees ne se lit plus quand les
        // icones se repetent : il faut relire chaque libelle.
        'comptoir' => '<path d="M3 10h18"/><path d="M5 10V6a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v4"/><path d="M4 10v10M20 10v10M8 14h8"/>',
        'preparation' => '<path d="M5 10h14a7 7 0 0 1-7 7 7 7 0 0 1-7-7z"/><path d="M12 17v4M8 21h8"/><path d="m16 3-3.5 5"/>',
        'categorie' => '<path d="M3 7a2 2 0 0 1 2-2h3.5l2 2.5H19a2 2 0 0 1 2 2V17a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M3 11h18"/>',
        'emplacement' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18M3 15h18M9 4v16"/>',
        'commande' => '<path d="M3 5h2l2.2 9.5A2 2 0 0 0 9.2 16h7.8a2 2 0 0 0 2-1.6L20.5 8H6"/><circle cx="10" cy="20" r="1.3"/><circle cx="17" cy="20" r="1.3"/>',
        'sablier' => '<path d="M7 3h10M7 21h10"/><path d="M7 3v3.5a5 5 0 0 0 5 5 5 5 0 0 0 5-5V3"/><path d="M7 21v-3.5a5 5 0 0 1 5-5 5 5 0 0 1 5 5V21"/>',
        'perte' => '<path d="M3 7.5 12 3l9 4.5v9L12 21l-9-4.5z"/><path d="M12 12v6"/><path d="m9 15 3 3 3-3"/>',
        'fournisseur' => '<path d="M2 7h11v9H2z"/><path d="M13 10h4l4 3.5V16h-8z"/><circle cx="6.5" cy="18.5" r="1.7"/><circle cx="17" cy="18.5" r="1.7"/>',
        'avoir' => '<path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/><path d="M9 13h6M9 16h4"/>',
        'soleil' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'lune' => '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
    ];
@endphp

<svg {{ $attributes->merge(['class' => 'ic']) }} viewBox="0 0 24 24" aria-hidden="true" focusable="false">{!! $paths[$name] ?? $paths['point'] !!}</svg>
