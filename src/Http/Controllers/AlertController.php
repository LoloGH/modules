<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Keneya\FinanceCaisse\Services\AlertCenter;

/**
 * Les alertes de celui qui regarde : ce qui attend un geste de sa part.
 *
 * Aucune permission propre : chaque alerte est déjà filtrée par le droit
 * d'agir dessus (voir AlertCenter). Quelqu'un qui n'a rien à faire ici voit
 * une page vide, et c'est la bonne réponse.
 */
final class AlertController extends FinanceController
{
    public function index(Request $request, AlertCenter $alerts): View
    {
        return view('finance::alerts.index', [
            'alerts' => $alerts->for($this->user($request)),
        ]);
    }
}
