<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Keneya\Pharmacie\Pharmacie;
use Keneya\Pharmacie\Services\Analytics;

/**
 * Tableau de bord du module. Contrôleur et non closure : une route à closure
 * empêche `route:cache`, donc `artisan optimize` chez l'hôte.
 *
 * Les chiffres sont recalculés à chaque affichage, et seulement pour qui a
 * le droit de les voir : sans le droit de lire le stock, l'écran le dit au
 * lieu d'afficher un zéro qui passerait pour un fait. Aucun chiffre n'est
 * inventé, un écran vide vaut mieux qu'un chiffre faux.
 */
final class HomeController extends PharmacieController
{
    public function __invoke(Request $request, Analytics $analytics): View
    {
        $user = $request->user(config('pharmacie.access.guard') ?: null);
        $gate = $user === null ? null : Gate::forUser($user);

        $queues = $gate?->allows('pharmacie.queue.view') ? Pharmacie::queue()->queues() : [];

        return view('pharmacie::home', [
            'facility' => Pharmacie::facility()['name'] ?? '',
            'queues' => $queues,
            'waiting' => array_sum(array_map(static fn ($queue): int => $queue->waiting, $queues)),
            // L'hôte fournit-il sa file ? L'écran le dit plutôt que de
            // laisser croire que la pharmacie n'a personne à servir.
            'queueConnected' => Pharmacie::queue()->queues() !== [],
            'figures' => $gate?->allows('pharmacie.stock.view') ? $analytics->dashboard() : null,
            'canReport' => (bool) $gate?->allows('pharmacie.reports.view'),
        ]);
    }
}
