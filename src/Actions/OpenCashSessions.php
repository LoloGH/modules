<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Keneya\FinanceCaisse\Exceptions\FinanceRuleViolation;
use Keneya\FinanceCaisse\Models\CashRegister;
use Keneya\FinanceCaisse\Models\CashSession;

/**
 * Ouvre plusieurs caisses d'un coup, chacune avec son fonds initial.
 *
 * Un caissier autorisé à tenir plusieurs tiroirs (Ticket, Services, …)
 * ouvre sa journée en une fois au lieu de trois. Chaque caisse garde sa
 * propre session — son fonds, ses mouvements, sa clôture et son écart : un
 * tiroir reste un tiroir.
 *
 * Tout ou rien : chaque ouverture passe par `OpenCashSession` et ses règles
 * (affectation, caisse libre, limite), dans une seule transaction. Si une
 * seule caisse est refusée, aucune n'est ouverte, et le message dit laquelle.
 */
final class OpenCashSessions
{
    public function __construct(private readonly OpenCashSession $open) {}

    /**
     * @param  array<int, int>  $floats  fonds initial par identifiant de caisse
     * @return list<CashSession>
     */
    public function handle(array $floats, Authenticatable $cashier): array
    {
        if ($floats === []) {
            throw new FinanceRuleViolation('Cochez au moins une caisse à ouvrir.');
        }

        return DB::transaction(function () use ($floats, $cashier): array {
            $sessions = [];

            foreach ($floats as $registerId => $float) {
                $register = CashRegister::query()->findOrFail($registerId);

                try {
                    $sessions[] = $this->open->handle($register, $cashier, $float);
                } catch (FinanceRuleViolation $e) {
                    throw new FinanceRuleViolation(sprintf(
                        'Aucune session ouverte. %s : %s',
                        $register->name,
                        $e->getMessage(),
                    ));
                }
            }

            return $sessions;
        });
    }
}
