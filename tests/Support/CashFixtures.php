<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Support;

use Keneya\FinanceCaisse\Actions\OpenCashSession;
use Keneya\FinanceCaisse\Exceptions\FinanceRuleViolation;
use Keneya\FinanceCaisse\Models\CashRegister;
use Keneya\FinanceCaisse\Models\CashSession;
use Keneya\FinanceCaisse\Models\PaymentMethod;

/**
 * Données de test communes aux scénarios de caisse.
 */
trait CashFixtures
{
    protected function makeRegister(string $code = 'CAISSE-1', bool $active = true): CashRegister
    {
        return CashRegister::firstOrCreate(
            ['code' => $code],
            ['name' => "Caisse {$code}", 'is_active' => $active],
        );
    }

    protected function makeMethod(string $code, string $kind, bool $requiresReference = false, bool $active = true): PaymentMethod
    {
        return PaymentMethod::firstOrCreate(
            ['code' => $code],
            ['name' => ucfirst($code), 'kind' => $kind, 'requires_reference' => $requiresReference, 'is_active' => $active],
        );
    }

    protected function cashMethod(): PaymentMethod
    {
        return $this->makeMethod('especes', PaymentMethod::KIND_CASH);
    }

    protected function momoMethod(): PaymentMethod
    {
        return $this->makeMethod('mobile_money', PaymentMethod::KIND_MOBILE_MONEY, true);
    }

    protected function openSession(TestUser $cashier, int $float = 10_000, ?CashRegister $register = null): CashSession
    {
        return app(OpenCashSession::class)->handle($register ?? $this->makeRegister(), $cashier, $float);
    }

    /**
     * Vérifie qu'une règle métier est violée, avec un message qui contient
     * le fragment donné.
     */
    protected function assertViolation(string $messageFragment, callable $action): void
    {
        try {
            $action();
        } catch (FinanceRuleViolation $e) {
            $this->assertStringContainsString($messageFragment, $e->getMessage());

            return;
        }

        $this->fail("Une violation de règle contenant « {$messageFragment} » était attendue.");
    }
}
