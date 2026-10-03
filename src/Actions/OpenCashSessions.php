<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Keneya\FinanceCaisse\Exceptions\FinanceRuleViolation;
use Keneya\FinanceCaisse\Models\CashierSetting;
use Keneya\FinanceCaisse\Models\CashRegister;
use Keneya\FinanceCaisse\Models\CashSession;
use Keneya\FinanceCaisse\Support\Actor;

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

        // Un tiroir pour un groupe, un par caisse quand elles sont séparées.
        $this->assertFitsTheLimit($drawer === null ? count($floats) : 1, $cashier);

        return DB::transaction(function () use ($floats, $cashier, $drawer): array {
            $sessions = [];

            foreach ($floats as $registerId => $float) {
                $register = CashRegister::query()->findOrFail($registerId);

                try {
                    $sessions[] = $this->open->handle($register, $cashier, $float, $drawer);
                } catch (FinanceRuleViolation $e) {
                    throw new FinanceRuleViolation(sprintf(
                        'Aucune caisse n\'a été ouverte. %s : %s',
                        $register->name,
                        $e->getMessage(),
                    ));
                }
            }

            return $sessions;
        });
    }

    /**
     * La demande tient-elle dans la limite du caissier, avant d'ouvrir quoi
     * que ce soit ?
     *
     * Sans ce contrôle, la vérification se faisait caisse par caisse, à
     * l'intérieur de la transaction. Trois caisses séparées demandées par un
     * caissier limité à un tiroir ouvraient la première, puis butaient sur la
     * deuxième avec « Ce caissier tient déjà un tiroir ouvert » : une phrase
     * qui décrivait le tiroir ouvert une ligne plus haut, aussitôt annulé par
     * le retour en arrière. Le caissier, lui, n'en tenait aucun, et lisait
     * qu'il devait clôturer une session qui n'existait pas.
     *
     * La question se pose donc sur la demande entière : combien de tiroirs
     * elle réclame, combien en restent. Et la réponse dit ce qui bloque, et
     * les deux sorties possibles : les grouper, ou faire relever la limite.
     *
     * Le contrôle par caisse reste en place dans `OpenCashSession` : il tient
     * la règle pour les ouvertures une par une, et sert de dernier recours.
     */
    private function assertFitsTheLimit(int $demandes, Authenticatable $cashier): void
    {
        $cashierId = Actor::id($cashier);
        $limite = CashierSetting::limitFor($cashierId);
        $tenus = CashSession::openDrawersFor($cashierId);

        if ($tenus + $demandes <= $limite) {
            return;
        }

        $dejaOuverts = $tenus > 0
            ? sprintf('Vous tenez déjà %d tiroir(s) sur %d autorisé(s). ', $tenus, $limite)
            : sprintf('Vous ne pouvez tenir que %d tiroir(s) à la fois. ', $limite);

        throw new FinanceRuleViolation($demandes > 1
            ? $dejaOuverts.sprintf(
                '%d caisses séparées demandent %d tiroirs. Ouvrez-les ensemble, avec un seul fonds : elles n\'en font alors qu\'un. Sinon, un administrateur relève votre limite (écran « Caisses »).',
                $demandes,
                $demandes,
            )
            : $dejaOuverts.'Clôturez un tiroir avant d\'en ouvrir un autre, ou ouvrez vos caisses ensemble, avec un seul fonds.');
    }
}
