<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Keneya\FinanceCaisse\Exceptions\FinanceRuleViolation;
use Keneya\FinanceCaisse\Models\CashRegister;
use Keneya\FinanceCaisse\Models\CashSession;

/**
 * Ouvre plusieurs caisses d'un coup, avec UN seul fonds initial.
 *
 * Le cas visé : un seul caissier encaisse tout (Ticket, Services, Pharmacie…)
 * avec un seul tiroir. Il coche ses caisses et saisit le fonds qu'il a en
 * main, une fois. Chaque caisse garde sa propre session — ses mouvements, sa
 * clôture —, mais le fonds n'est compté qu'une fois : il est porté par la
 * première caisse cochée, les autres s'ouvrent à zéro. La somme des fonds
 * initiaux est ainsi exactement le fonds réel.
 *
 * Tout ou rien : chaque ouverture passe par `OpenCashSession` et ses règles
 * (affectation, caisse libre, limite), dans une seule transaction. Si une
 * seule caisse est refusée, aucune n'est ouverte, et le message dit laquelle.
 */
final class OpenCashSessions
{
    public function __construct(private readonly OpenCashSession $open) {}

    /**
     * @param  list<int>  $registerIds  dans l'ordre coché ; la première porte le fonds
     * @return list<CashSession>
     */
    public function handle(array $registerIds, int $openingFloat, Authenticatable $cashier): array
    {
        if ($registerIds === []) {
            throw new FinanceRuleViolation('Cochez au moins une caisse à ouvrir.');
        }

        if ($openingFloat < 0) {
            throw new FinanceRuleViolation('Le fonds initial ne peut pas être négatif.');
        }

        return DB::transaction(function () use ($registerIds, $openingFloat, $cashier): array {
            $sessions = [];

            foreach (array_values($registerIds) as $index => $registerId) {
                $register = CashRegister::query()->findOrFail($registerId);

                try {
                    $sessions[] = $this->open->handle($register, $cashier, $index === 0 ? $openingFloat : 0);
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
