<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature\Http;

use Keneya\FinanceCaisse\Support\Rbac;
use Keneya\FinanceCaisse\Tests\Support\CashFixtures;
use Keneya\FinanceCaisse\Tests\Support\TestUser;
use Keneya\FinanceCaisse\Tests\TestCase;

/**
 * Socle des tests d'écrans : les vrais rôles sont créés par la commande de
 * synchronisation, comme chez l'hôte. L'hôte accorde l'accès au module (la
 * capacité est définie par TestCase) ; les droits fins viennent des rôles.
 */
abstract class HttpTestCase extends TestCase
{
    use CashFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('finance:sync-permissions')->assertSuccessful();
    }

    protected function cashier(): TestUser
    {
        return $this->userWithRole(Rbac::ROLE_CASHIER);
    }

    protected function accountant(): TestUser
    {
        return $this->userWithRole(Rbac::ROLE_ACCOUNTANT);
    }

    protected function admin(): TestUser
    {
        return $this->userWithRole(Rbac::ROLE_ADMIN);
    }
}
