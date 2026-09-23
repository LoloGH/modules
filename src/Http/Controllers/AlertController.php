<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Keneya\Pharmacie\Services\AlertCenter;

/**
 * Les alertes de celui qui regarde : ce qui attend un geste de sa part.
 *
 * Aucune permission propre : chaque alerte est déjà filtrée par le droit
 * d'agir dessus (voir AlertCenter). Quelqu'un qui n'a rien à faire ici voit
 * une page vide, et c'est la bonne réponse.
 */
final class AlertController extends PharmacieController
{
    public function index(Request $request, AlertCenter $alerts): View
    {
        return view('pharmacie::alerts.index', [
            'alerts' => $alerts->for($this->user($request)),
        ]);
    }
}
