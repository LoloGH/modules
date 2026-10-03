<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Keneya\FinanceCaisse\Actions\Concerns\GuardsCashSession;
use Keneya\FinanceCaisse\Audit\Auditor;
use Keneya\FinanceCaisse\Exceptions\FinanceRuleViolation;
use Keneya\FinanceCaisse\Models\CashSession;
use Keneya\FinanceCaisse\Support\Actor;
use Keneya\FinanceCaisse\Support\Money;
use Keneya\FinanceCaisse\Support\Text;

/**
 * Valide une clôture. Séparation des tâches : le caissier ne valide jamais sa
 * propre clôture.
 *
 * **On valide un tiroir, pas une caisse.** Des caisses ouvertes groupées
 * partagent un tiroir, qui s'est compté une seule fois et se clôture d'un coup
 * ({@see CloseCashSession}). Il n'y a donc qu'un comptage et qu'un écart à
 * contrôler, et les présenter en trois validations distinctes donnait au
 * contrôle trois gestes pour un seul fait, dont deux portant sur des lignes à
 * zéro. Le risque était d'en valider une et d'oublier les autres, laissant un
 * tiroir à moitié contrôlé.
 *
 * Un tiroir se valide donc en une fois : toutes ses caisses passent ensemble.
 * Chacune garde sa ligne et son journal, parce que chacune reste une session
 * avec ses propres mouvements.
 */
final class ValidateCashSession
{
    use GuardsCashSession;

    public function __construct(private readonly Auditor $auditor) {}

    public function handle(CashSession $session, Authenticatable $validator, ?string $note = null): CashSession
    {
        return DB::transaction(function () use ($session, $validator, $note): CashSession {
            $sessions = $session->drawerSessions(lock: true);

            foreach ($sessions as $item) {
                $this->assertCanBeValidated($item, $validator);
            }

            $note = Text::clean($note);

            $this->validateAll($sessions, $validator, $note);

            return $session->refresh();
        });
    }

    private function assertCanBeValidated(CashSession $session, Authenticatable $validator): void
    {
        if ($session->isValidated()) {
            throw new FinanceRuleViolation("La session {$session->number} est déjà validée.");
        }

        if (! $session->isClosed()) {
            throw new FinanceRuleViolation("Seule une session clôturée peut être validée ({$session->number} est encore ouverte).");
        }

        if ((string) $session->cashier_id === Actor::id($validator)) {
            throw new FinanceRuleViolation('Le caissier ne peut pas valider sa propre clôture.');
        }
    }

    /**
     * @param  Collection<int, CashSession>  $sessions
     */
    private function validateAll(Collection $sessions, Authenticatable $validator, ?string $note): void
    {
        $groupe = $sessions->count() > 1;

        foreach ($sessions as $item) {
            $item->update([
                'status' => CashSession::STATUS_VALIDATED,
                'validated_at' => now(),
                'validator_id' => Actor::id($validator),
                'validator_name' => Actor::name($validator),
                'validation_note' => $note,
            ]);

            // La validation porte sur des chiffres : on les écrit dans le
            // journal, pour que la ligne se lise sans rouvrir la session. Pour
            // un tiroir commun, ce sont les siens : c'est lui qui a été compté.
            $this->auditor->record(
                'session_validated',
                $item,
                sprintf(
                    'Session %s validée%s : théorique %s, compté %s, écart %s (clôturée par %s)',
                    $item->number,
                    $groupe ? sprintf(' avec son tiroir (%d caisses)', $sessions->count()) : '',
                    Money::format((int) $sessions->sum('expected_cash')),
                    Money::format((int) $sessions->sum('counted_cash')),
                    Money::format((int) $sessions->sum('variance')),
                    $item->cashier_name ?? $item->cashier_id,
                ),
                ['status' => CashSession::STATUS_CLOSED],
                [
                    'status' => CashSession::STATUS_VALIDATED,
                    'expected_cash' => (int) $item->expected_cash,
                    'counted_cash' => (int) $item->counted_cash,
                    'variance' => (int) $item->variance,
                    'variance_reason' => $item->variance_reason,
                    'cashier' => $item->cashier_name ?? $item->cashier_id,
                    'note' => $note,
                ],
                $validator,
            );
        }
    }
}
