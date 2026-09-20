<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature;

use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Keneya\FinanceCaisse\Services\NumberGenerator;
use Keneya\FinanceCaisse\Tests\TestCase;

/**
 * Numérotation des factures, paiements, sessions… : jamais de doublon.
 * (Le verrou de ligne se joue sur MySQL ; la suite tourne sur SQLite, qui
 * sérialise les écritures. On vérifie ici le format et les règles.)
 */
class NumberGeneratorTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_first_number_has_the_documented_format(): void
    {
        Carbon::setTestNow('2026-09-20 10:00:00');

        $this->assertSame('FAC-2026-000001', app(NumberGenerator::class)->next('invoice'));
    }

    public function test_numbers_increase_one_by_one(): void
    {
        Carbon::setTestNow('2026-09-20 10:00:00');
        $generator = app(NumberGenerator::class);

        $this->assertSame('FAC-2026-000001', $generator->next('invoice'));
        $this->assertSame('FAC-2026-000002', $generator->next('invoice'));
        $this->assertSame('FAC-2026-000003', $generator->next('invoice'));
    }

    public function test_each_kind_of_document_has_its_own_counter(): void
    {
        Carbon::setTestNow('2026-09-20 10:00:00');
        $generator = app(NumberGenerator::class);

        $generator->next('invoice');
        $generator->next('invoice');

        $this->assertSame('PAI-2026-000001', $generator->next('payment'));
        $this->assertSame('SES-2026-000001', $generator->next('cash_session'));
    }

    public function test_the_counter_restarts_every_year(): void
    {
        $generator = app(NumberGenerator::class);

        Carbon::setTestNow('2026-12-31 23:59:00');
        $this->assertSame('FAC-2026-000001', $generator->next('invoice'));
        $this->assertSame('FAC-2026-000002', $generator->next('invoice'));

        Carbon::setTestNow('2027-01-01 00:01:00');
        $this->assertSame('FAC-2027-000001', $generator->next('invoice'));

        // L'année précédente garde son compte : rien n'est réutilisé.
        Carbon::setTestNow('2026-12-31 23:59:30');
        $this->assertSame('FAC-2026-000003', $generator->next('invoice'));
    }

    public function test_the_padding_comes_from_the_configuration(): void
    {
        Carbon::setTestNow('2026-09-20 10:00:00');
        config()->set('finance.identifiers.padding', 3);

        $this->assertSame('FAC-2026-001', app(NumberGenerator::class)->next('invoice'));
    }

    public function test_an_unknown_kind_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(NumberGenerator::class)->next('inconnu');
    }
}
