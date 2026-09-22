<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Keneya\FinanceCaisse\Exceptions\FinanceRuleViolation;
use Keneya\FinanceCaisse\Models\CashRegister;
use Keneya\FinanceCaisse\Models\CashSession;

/**
 * Ouvre plusieurs caisses d'un coup, de deux façons :
 *
 *  - **groupées** : un seul caissier, un seul tiroir, un seul fonds. Les
 *    caisses cochées partagent un tiroir (`drawer_key`) ; le fonds est porté
 *    par la première, les autres s'ouvrent à zéro. Le groupe compte pour UN
 *    tiroir dans la limite du caissier.
 *  - **séparées** : un tiroir et un fonds par caisse. Chacune compte dans sa
 *    limite.
 *
 * Dans les deux cas chaque caisse garde sa propre session et sa clôture.
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
    public function grouped(array $registerIds, int $openingFloat, Authenticatable $cashier): array
    {
        if ($openingFloat < 0) {
            throw new FinanceRuleViolation('Le fonds initial ne peut pas être négatif.');
        }

        $drawer = count($registerIds) > 1 ? (string) Str::uuid() : null;
        $floats = [];

        foreach (array_values($registerIds) as $index => $registerId) {
            $floats[$registerId] = $index === 0 ? $openingFloat : 0;
        }

        return $this->openAll($floats, $cashier, $drawer);
    }

    /**
     * @param  array<int, int>  $floats  fonds initial par identifiant de caisse
     * @return list<CashSession>
     */
    public function separate(array $floats, Authenticatable $cashier): array
    {
        return $this->openAll($floats, $cashier, null);
    }

    /**
     * @param  array<int, int>  $floats
     * @return list<CashSession>
     */
    private function openAll(array $floats, Authenticatable $cashier, ?string $drawer): array
    {
        if ($floats === []) {
            throw new FinanceRuleViolation('Cochez au moins une caisse à ouvrir.');
        }

        return DB::transaction(function () use ($floats, $cashier, $drawer): array {
            $sessions = [];

            foreach ($floats as $registerId => $float) {
                $register = CashRegister::query()->findOrFail($registerId);

                try {
                    $sessions[] = $this->open->handle($register, $cashier, $float, $drawer);
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
