<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Keneya\FinanceCaisse\Access\FinanceAccessGate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse toute page du module tant que l'hôte n'a pas accordé l'accès.
 *
 * Visiteur sans session : renvoi vers la page de connexion de l'hôte.
 * Utilisateur connecté sans accord : 403.
 */
final class EnsureHostGrantsAccess
{
    public function __construct(private readonly FinanceAccessGate $gate) {}

    public function handle(Request $request, Closure $next): Response
    {
        $guard = config('finance.access.guard');
        $guard = is_string($guard) && $guard !== '' ? $guard : null;

        $user = $request->user($guard);

        if ($this->gate->allows($user, $request)) {
            return $next($request);
        }

        if ($user === null) {
            throw new AuthenticationException('Unauthenticated.', $guard === null ? [] : [$guard]);
        }

        abort(403, $this->gate->denialReason($user));
    }
}
