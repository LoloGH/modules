<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature;

use Keneya\FinanceCaisse\Actions\CreateInvoice;
use Keneya\FinanceCaisse\Actions\SetInsurerCoverage;
use Keneya\FinanceCaisse\Models\Act;
use Keneya\FinanceCaisse\Models\AuditLog;
use Keneya\FinanceCaisse\Models\Insurer;
use Keneya\FinanceCaisse\Tests\Support\CashFixtures;
use Keneya\FinanceCaisse\Tests\Support\CatalogFixtures;
use Keneya\FinanceCaisse\Tests\TestCase;

/**
 * La couverture acte par acte : tous les actes sauf exclusions et taux
 * propres, ou seulement les actes choisis ; appliquée ligne par ligne.
 */
class ActCoverageTest extends TestCase
{
    use CashFixtures;
    use CatalogFixtures;

    private function act(string $code, int $amount): Act
    {
        $act = $this->makeAct($code);
        $this->setTariff($act, $amount);

        return $act;
    }

    private function insurer(string $kind = Insurer::KIND_INSURANCE, int $rate = 80): Insurer
    {
        static $n = 0;
        $n++;

        return Insurer::create(['code' => 'ORG-'.$n, 'name' => 'Organisme '.$n, 'kind' => $kind, 'default_rate' => $rate, 'is_active' => true]);
    }

    public function test_scope_all_covers_every_act_except_exclusions_and_with_own_rates(): void
    {
        $cons = $this->act('CONS', 2_000);
        $echo = $this->act('ECHO', 10_000);
        $pharma = $this->act('PHARMA', 1_000);
        $insurer = $this->insurer(rate: 80);

        app(SetInsurerCoverage::class)->handle($insurer, Insurer::SCOPE_ALL, 80, [
            $cons->id => ['covered' => true, 'rate' => null],
            $echo->id => ['covered' => true, 'rate' => 50],
            $pharma->id => ['covered' => false, 'rate' => null],
        ], $this->makeUser());

        $insurer->refresh();
        $this->assertSame(80, $insurer->rateFor($cons->id));
        $this->assertSame(50, $insurer->rateFor($echo->id));
        $this->assertSame(0, $insurer->rateFor($pharma->id));
        $this->assertSame(80, $insurer->rateFor($this->act('AUTRE', 500)->id));   // un nouvel acte est couvert
        $this->assertSame(1, AuditLog::where('event', 'insurer_coverage_set')->count());
    }

    public function test_scope_selected_covers_only_the_checked_acts(): void
    {
        $cons = $this->act('CONS', 2_000);
        $echo = $this->act('ECHO', 10_000);
        $aid = $this->insurer(Insurer::KIND_SOCIAL_AID, 100);

        app(SetInsurerCoverage::class)->handle($aid, Insurer::SCOPE_SELECTED, 100, [
            $cons->id => ['covered' => true, 'rate' => null],
            $echo->id => ['covered' => false, 'rate' => 70],
        ], $this->makeUser());

        $aid->refresh();
        $this->assertSame(100, $aid->rateFor($cons->id));
        $this->assertSame(0, $aid->rateFor($echo->id));
        $this->assertSame('Aide sociale', $aid->kindLabel());

        $this->assertViolation('au moins un acte', fn () => app(SetInsurerCoverage::class)->handle($aid, Insurer::SCOPE_SELECTED, 100, [], $this->makeUser()));
        $this->assertViolation('entre 1 et 100', fn () => app(SetInsurerCoverage::class)->handle($aid, Insurer::SCOPE_ALL, 0, [], $this->makeUser()));
    }

    public function test_each_invoice_line_gets_its_own_rate_and_uncovered_acts_stay_with_the_patient(): void
    {
        $cons = $this->act('CONS', 2_000);
        $echo = $this->act('ECHO', 10_000);
        $pharma = $this->act('PHARMA', 1_000);
        $insurer = $this->insurer(rate: 80);
        app(SetInsurerCoverage::class)->handle($insurer, Insurer::SCOPE_ALL, 80, [
            $echo->id => ['covered' => true, 'rate' => 50],
            $pharma->id => ['covered' => false, 'rate' => null],
        ], $this->makeUser());

        $invoice = app(CreateInvoice::class)->handle('PAT-1', 'Awa', [
            ['act_id' => $cons->id, 'quantity' => 1],    // 80 % de 2 000 = 1 600
            ['act_id' => $echo->id, 'quantity' => 1],    // 50 % de 10 000 = 5 000
            ['act_id' => $pharma->id, 'quantity' => 2],  // exclu : 2 000 au patient
        ], null, $this->makeUser(), ['insurer_id' => $insurer->id]);

        $lines = $invoice->lines()->orderBy('id')->get();
        $this->assertSame([80, 50, 0], $lines->pluck('insurer_rate')->all());
        $this->assertSame([1_600, 5_000, 0], $lines->pluck('insurer_share')->all());
        $this->assertSame([400, 5_000, 2_000], $lines->pluck('patient_share')->all());

        $this->assertSame(14_000, $invoice->total);
        $this->assertSame(6_600, $invoice->insurer_share);
        $this->assertSame(7_400, $invoice->patient_share);
        $this->assertSame(47, $invoice->coverage_rate);   // 6 600 / 14 000, arrondi
    }

    public function test_changing_the_coverage_does_not_touch_issued_invoices(): void
    {
        $cons = $this->act('CONS', 2_000);
        $insurer = $this->insurer(rate: 80);

        $invoice = app(CreateInvoice::class)->handle(null, 'Awa', [['act_id' => $cons->id, 'quantity' => 1]], null, $this->makeUser(), ['insurer_id' => $insurer->id]);

        app(SetInsurerCoverage::class)->handle($insurer, Insurer::SCOPE_ALL, 50, [], $this->makeUser());

        $this->assertSame(1_600, $invoice->fresh()->insurer_share);
        $this->assertSame(80, $invoice->lines()->first()->insurer_rate);
    }
}
