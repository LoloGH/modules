<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Exceptions;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Une règle métier de la caisse est violée (session fermée, montant nul,
 * écart non justifié…). Le message est en français et destiné à l'utilisateur.
 *
 * Levée depuis un écran, elle se rend d'elle-même : retour au formulaire avec
 * le message et la saisie conservée (ou une réponse 422 pour une requête
 * JSON). Ce n'est pas un bogue : elle n'est pas écrite dans le journal
 * d'erreurs.
 */
final class FinanceRuleViolation extends DomainException
{
    public function render(Request $request): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 422);
        }

        return redirect()->back()->withInput()->with('finance_error', $this->getMessage());
    }

    public function report(): bool
    {
        return true;
    }
}
