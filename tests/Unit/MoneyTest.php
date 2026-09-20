<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Unit;

use Keneya\FinanceCaisse\Support\Money;
use Keneya\FinanceCaisse\Tests\TestCase;

class MoneyTest extends TestCase
{
    public function test_amounts_are_formatted_in_francs_cfa_without_decimals(): void
    {
        $this->assertSame('0 FCFA', Money::format(0));
        $this->assertSame('500 FCFA', Money::format(500));
        $this->assertSame('5 000 FCFA', Money::format(5_000));
        $this->assertSame('1 250 000 FCFA', Money::format(1_250_000));
        $this->assertSame('-1 000 FCFA', Money::format(-1_000));
    }
}
