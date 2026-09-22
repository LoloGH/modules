<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Keneya\FinanceCaisse\Audit\Auditor;
use Keneya\FinanceCaisse\Exceptions\FinanceRuleViolation;
use Keneya\FinanceCaisse\Models\CashierRegister;
use Keneya\FinanceCaisse\Models\CashierSetting;
use Keneya\FinanceCaisse\Models\CashRegister;
use Keneya\FinanceCaisse\Models\CashSession;
use Keneya\FinanceCaisse\Services\NumberGenerator;
use Keneya\FinanceCaisse\Support\Actor;
use Keneya\FinanceCaisse\Support\Money;

/**
 * Ouvre une session de caisse.
 *
 * Règles : une seule session ouverte par caisse (deux personnes ne tiennent
 * jamais le même tiroir), le caissier est affecté à cette caisse, il ne
 * dépasse pas sa limite de sessions ouvertes simultanées, une caisse
 * désactivée ne s'ouvre pas, le fonds initial n'est pas négatif.
 *
 * La limite vaut 1 par défaut — un caissier, un tiroir — et se règle par
 * établissement dans la configuration, puis caissier par caissier dans
 * `finance_cashier_settings`.
 */
final class OpenCashSession
{
    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  ?string  $drawerKey  tiroir partagé par des caisses ouvertes
     *                              ensemble ; null pour une caisse seule
     */
    public function handle(CashRegister $register, Authenticatable $cashier, int $openingFloat, ?string $drawerKey = null): CashSession
    {
        if ($openingFloat < 0) {
            throw new FinanceRuleViolation('Le fonds initial ne peut pas être négatif.');
        }

        return DB::transaction(function () use ($register, $cashier, $openingFloat, $drawerKey): CashSession {
            // Verrou sur la caisse : deux ouvertures simultanées se succèdent
            // au lieu de se croiser.
            $register = CashRegister::query()->whereKey($register->getKey())->lockForUpdate()->firstOrFail();

            if (! $register->is_active) {
                throw new FinanceRuleViolation("La caisse {$register->name} est désactivée.");
            }

            $cashierId = Actor::id($cashier);

            $this->assertAssignedTo($register, $cashierId);
            $this->assertRegisterIsFree($register, $cashierId);
            $this->assertUnderLimit($cashierId, $drawerKey);

            $session = CashSession::create([
                'number' => $this->numbers->next('cash_session'),
                'cash_register_id' => $register->id,
                'cashier_id' => $cashierId,
                'cashier_name' => Actor::name($cashier),
                'drawer_key' => $drawerKey,
                'status' => CashSession::STATUS_OPEN,
                'opening_float' => $openingFloat,
                'opened_at' => now(),
            ]);

            $this->auditor->record(
                'session_opened',
                $session,
                sprintf('Session %s ouverte sur %s, fonds initial %s', $session->number, $register->name, Money::format($openingFloat)),
                [],
                ['cash_register' => $register->code, 'opening_float' => $openingFloat],
                $cashier,
            );

            return $session;
        });
    }

    /**
     * Le caissier est affecté à cette caisse — ou n'est restreint à aucune,
     * ce qui les autorise toutes.
     */
    private function assertAssignedTo(CashRegister $register, string $cashierId): void
    {
        if (! CashierRegister::allows($cashierId, (int) $register->id)) {
            throw new FinanceRuleViolation("Vous n'êtes pas affecté à la caisse {$register->name}.");
        }
    }

    /**
     * Un tiroir, une personne. Le message distingue les deux cas : se voir
     * répondre « une session est déjà ouverte » quand c'est la sienne
     * n'aiderait personne.
     */
    private function assertRegisterIsFree(CashRegister $register, string $cashierId): void
    {
        $occupant = CashSession::query()->open()->where('cash_register_id', $register->id)->first();

        if ($occupant === null) {
            return;
        }

        if ((string) $occupant->cashier_id === $cashierId) {
            throw new FinanceRuleViolation("Vous tenez déjà la caisse {$register->name} (session {$occupant->number}).");
        }

        throw new FinanceRuleViolation('Une session est déjà ouverte sur cette caisse.');
    }

    /**
     * Le caissier reste sous sa limite de tiroirs ouverts simultanément.
     *
     * Une caisse qui rejoint un tiroir déjà ouvert (ouverture groupée, un seul
     * fonds) n'en ajoute pas. Le verrou porte sur ses sessions ouvertes : deux
     * ouvertures simultanées se succèdent au lieu de compter toutes les deux
     * l'état d'avant.
     */
    private function assertUnderLimit(string $cashierId, ?string $drawerKey): void
    {
        $limit = CashierSetting::limitFor($cashierId);

        $open = CashSession::openDrawersFor($cashierId, lock: true);

        $joinsOpenDrawer = $drawerKey !== null && CashSession::query()->open()
            ->where('cashier_id', $cashierId)
            ->where('drawer_key', $drawerKey)
            ->exists();

        if ($joinsOpenDrawer || $open < $limit) {
            return;
        }

        throw new FinanceRuleViolation($limit === 1
            ? 'Ce caissier tient déjà un tiroir ouvert : clôturez-le avant d\'en ouvrir un autre, ou ouvrez vos caisses ensemble avec un seul fonds.'
            : sprintf('Vous tenez déjà %d tiroir(s) ouvert(s), limite atteinte.', $open));
    }
}
