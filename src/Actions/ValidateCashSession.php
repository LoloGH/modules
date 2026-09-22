<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Keneya\FinanceCaisse\Actions\Concerns\GuardsCashSession;
use Keneya\FinanceCaisse\Audit\Auditor;
use Keneya\FinanceCaisse\Exceptions\FinanceRuleViolation;
use Keneya\FinanceCaisse\Models\CashSession;
use Keneya\FinanceCaisse\Support\Actor;
use Keneya\FinanceCaisse\Support\Money;
use Keneya\FinanceCaisse\Support\Text;

/**
 * Valide une session clôturée. Séparation des tâches : le caissier ne valide
 * jamais sa propre clôture.
 */
final class ValidateCashSession
{
    use GuardsCashSession;

    public function __construct(private readonly Auditor $auditor) {}

    public function handle(CashSession $session, Authenticatable $validator, ?string $note = null): CashSession
    {
        return DB::transaction(function () use ($session, $validator, $note): CashSession {
            $session = $this->lockSession($session);

            if ($session->isValidated()) {
                throw new FinanceRuleViolation("La session {$session->number} est déjà validée.");
            }

            if (! $session->isClosed()) {
                throw new FinanceRuleViolation("Seule une session clôturée peut être validée ({$session->number} est encore ouverte).");
            }

            if ((string) $session->cashier_id === Actor::id($validator)) {
                throw new FinanceRuleViolation('Le caissier ne peut pas valider sa propre clôture.');
            }

            $note = Text::clean($note);

            $session->update([
                'status' => CashSession::STATUS_VALIDATED,
                'validated_at' => now(),
                'validator_id' => Actor::id($validator),
                'validator_name' => Actor::name($validator),
                'validation_note' => $note,
            ]);

            // La validation porte sur des chiffres : on les écrit dans le
            // journal, pour que la ligne se lise sans rouvrir la session.
            $this->auditor->record(
                'session_validated',
                $session,
                sprintf(
                    'Session %s validée : théorique %s, compté %s, écart %s (clôturée par %s)',
                    $session->number,
                    Money::format((int) $session->expected_cash),
                    Money::format((int) $session->counted_cash),
                    Money::format((int) $session->variance),
                    $session->cashier_name ?? $session->cashier_id,
                ),
                ['status' => CashSession::STATUS_CLOSED],
                [
                    'status' => CashSession::STATUS_VALIDATED,
                    'expected_cash' => (int) $session->expected_cash,
                    'counted_cash' => (int) $session->counted_cash,
                    'variance' => (int) $session->variance,
                    'variance_reason' => $session->variance_reason,
                    'cashier' => $session->cashier_name ?? $session->cashier_id,
                    'note' => $note,
                ],
                $validator,
            );

            return $session;
        });
    }
}
