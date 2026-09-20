<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Keneya\FinanceCaisse\Audit\Auditor;
use Keneya\FinanceCaisse\Exceptions\FinanceRuleViolation;
use Keneya\FinanceCaisse\Models\CashRegister;
use Keneya\FinanceCaisse\Models\CashSession;
use Keneya\FinanceCaisse\Services\NumberGenerator;
use Keneya\FinanceCaisse\Support\Actor;
use Keneya\FinanceCaisse\Support\Money;

/**
 * Ouvre une session de caisse.
 *
 * Règles : une seule session ouverte par caisse, une seule par caissier, une
 * caisse désactivée ne s'ouvre pas, le fonds initial n'est pas négatif.
 */
final class OpenCashSession
{
    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly Auditor $auditor,
    ) {}

    public function handle(CashRegister $register, Authenticatable $cashier, int $openingFloat): CashSession
    {
        if ($openingFloat < 0) {
            throw new FinanceRuleViolation('Le fonds initial ne peut pas être négatif.');
        }

        return DB::transaction(function () use ($register, $cashier, $openingFloat): CashSession {
            // Verrou sur la caisse : deux ouvertures simultanées se succèdent
            // au lieu de se croiser.
            $register = CashRegister::query()->whereKey($register->getKey())->lockForUpdate()->firstOrFail();

            if (! $register->is_active) {
                throw new FinanceRuleViolation("La caisse {$register->name} est désactivée.");
            }

            if (CashSession::query()->open()->where('cash_register_id', $register->id)->exists()) {
                throw new FinanceRuleViolation('Une session est déjà ouverte sur cette caisse.');
            }

            $cashierId = Actor::id($cashier);

            if (CashSession::query()->open()->where('cashier_id', $cashierId)->exists()) {
                throw new FinanceRuleViolation('Ce caissier a déjà une session ouverte.');
            }

            $session = CashSession::create([
                'number' => $this->numbers->next('cash_session'),
                'cash_register_id' => $register->id,
                'cashier_id' => $cashierId,
                'cashier_name' => Actor::name($cashier),
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
}
