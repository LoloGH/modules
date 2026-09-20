<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Keneya\FinanceCaisse\Access\FinanceAccessGate;
use Keneya\FinanceCaisse\Audit\Auditor;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Refuse toute page du module tant que l'hôte n'a pas accordé l'accès.
 *
 * Visiteur sans session : renvoi vers la page de connexion de l'hôte.
 * Utilisateur connecté sans accord : 403.
 */
final class EnsureHostGrantsAccess
{
    public function __construct(
        private readonly FinanceAccessGate $gate,
        private readonly Auditor $auditor,
    ) {}

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

        $this->auditDenial($request, $user);

        abort(403, $this->gate->denialReason($user));
    }

    /**
     * Trace le refus. C'est une simple trace : si le journal est
     * inaccessible (module installé mais pas encore migré), la réponse reste
     * un 403 et non une erreur 500.
     */
    private function auditDenial(Request $request, Authenticatable $user): void
    {
        try {
            $this->auditor->record(
                'access_denied',
                null,
                sprintf('Accès refusé : %s %s', $request->method(), $request->path()),
                [],
                [],
                $user,
            );
        } catch (Throwable $e) {
            report($e);
        }
    }
}
