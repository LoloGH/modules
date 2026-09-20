<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

abstract class FinanceController
{
    /**
     * L'utilisateur connecté. Les actions de caisse sont toujours faites par
     * quelqu'un de précis : sans utilisateur, on refuse plutôt que d'écrire
     * une opération sans auteur.
     */
    protected function user(Request $request): Authenticatable
    {
        $guard = config('finance.access.guard');
        $user = $request->user(is_string($guard) && $guard !== '' ? $guard : null);

        abort_if($user === null, 401, 'Aucune session ouverte.');

        return $user;
    }
}
