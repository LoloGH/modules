<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Controllers;

use Illuminate\Contracts\View\View;

/**
 * Page d'accueil du module. Contrôleur et non closure : une route à
 * closure empêche `route:cache`, donc `artisan optimize` chez l'hôte.
 */
final class HomeController
{
    public function __invoke(): View
    {
        return view('finance::home', [
            'facility' => (string) config('finance.facility.name'),
        ]);
    }
}
