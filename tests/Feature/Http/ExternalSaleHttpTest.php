<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature\Http;

use Keneya\FinanceCaisse\Actions\BillExternalSale;
use Keneya\FinanceCaisse\Contracts\CashQueueProvider;
use Keneya\FinanceCaisse\Contracts\VisitAdvancer;
use Keneya\FinanceCaisse\Models\AuditLog;
use Keneya\FinanceCaisse\Models\Insurer;
use Keneya\FinanceCaisse\Models\Invoice;
use Keneya\FinanceCaisse\Models\Payment;
use Keneya\FinanceCaisse\Queue\CashQueue;
use Keneya\FinanceCaisse\Tests\Support\FakeCashQueue;
use Keneya\FinanceCaisse\Tests\Support\FakeVisitAdvancer;
use Keneya\FinanceCaisse\Tests\Support\TestUser;

/**
 * Une vente venue d'un autre module : la pharmacie délivre, Finance facture,
 * la caisse encaisse.
 *
 * Ce qui est éprouvé ici : le prix vient du module vendeur et n'est pas
 * renégocié, la même pièce ne donne qu'une facture, et un patient qui ne doit
 * rien ne reste pas planté devant le guichet.
 */
class ExternalSaleHttpTest extends HttpTestCase
{
    private FakeCashQueue $queue;

    private FakeVisitAdvancer $advancer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->queue = new FakeCashQueue([new CashQueue('20', 'Caisse Pharmacie')]);
        $this->advancer = new FakeVisitAdvancer;
        $this->app->instance(CashQueueProvider::class, $this->queue);
        $this->app->instance(VisitAdvancer::class, $this->advancer);
    }

    public function test_a_pharmacy_sale_becomes_an_invoice_at_the_sellers_prices(): void
    {
        $invoice = $this->bill();

        $this->assertSame(2_100, (int) $invoice->total);
        $this->assertSame(2_100, $invoice->patientDue());
        $this->assertSame(BillExternalSale::SOURCE_PHARMACIE, $invoice->source);
        $this->assertSame('DIS-2026-000001', $invoice->source_reference);

        $lines = $invoice->lines()->orderBy('id')->get();

        $this->assertCount(2, $lines);
        // Aucune ligne ne vient du catalogue des actes : Finance ne connaît
        // pas l'amoxicilline, et n'a pas à la connaître.
        $this->assertNull($lines[0]->act_id);
        $this->assertSame('Amoxicilline 500 mg', $lines[0]->label);
        $this->assertSame(14, (int) $lines[0]->quantity);
        $this->assertSame(1_400, (int) $lines[0]->amount);
    }

    public function test_the_same_dispensation_never_gives_two_invoices(): void
    {
        $first = $this->bill();
        $second = $this->bill();

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Invoice::count());
    }

    public function test_a_declared_coverage_splits_what_each_one_owes(): void
    {
        Insurer::create(['code' => 'AMO', 'name' => 'AMO', 'kind' => Insurer::KIND_INSURANCE, 'default_rate' => 70, 'is_active' => true]);

        $invoice = $this->bill(['insurer' => 'AMO', 'rate' => 80, 'reference' => 'ACC-2026-12']);

        // Le taux est celui que le comptoir a constaté, pas celui du
        // catalogue : ces lignes ne sont pas des actes.
        $this->assertSame(80, (int) $invoice->coverage_rate);
        $this->assertSame(1_680, (int) $invoice->insurer_share);
        $this->assertSame(420, $invoice->patientDue());
        $this->assertSame('ACC-2026-12', $invoice->policy_number);
    }

    public function test_each_line_can_carry_its_own_rate(): void
    {
        Insurer::create(['code' => 'AMO', 'name' => 'AMO', 'kind' => Insurer::KIND_INSURANCE, 'default_rate' => 70, 'is_active' => true]);

        // L'organisme couvre l'amoxicilline a 80 %, et pas le paracetamol :
        // un taux unique aurait couvert les deux, ou aucun.
        $invoice = app(BillExternalSale::class)->handle(
            BillExternalSale::SOURCE_PHARMACIE,
            'DIS-2026-000002',
            'PAT-00003',
            'Sylla Baba',
            [
                ['label' => 'Amoxicilline 500 mg', 'quantity' => 14, 'unit_price' => 100, 'insurer_rate' => 80],
                ['label' => 'Paracetamol 1 g', 'quantity' => 7, 'unit_price' => 100, 'insurer_rate' => 0],
            ],
            $this->cashier(),
            ['insurer' => 'AMO', 'rate' => 80],
        );

        $lines = $invoice->lines()->orderBy('id')->get();

        $this->assertSame(80, (int) $lines[0]->insurer_rate);
        $this->assertSame(1_120, (int) $lines[0]->insurer_share);
        $this->assertSame(0, (int) $lines[1]->insurer_rate);
        $this->assertSame(700, (int) $lines[1]->patient_share);

        // Le taux de la piece est celui que portent ses lignes : 1 120 sur
        // 2 100, soit 53 %, et non les 80 % annonces pour l'organisme.
        $this->assertSame(1_120, (int) $invoice->insurer_share);
        $this->assertSame(980, $invoice->patientDue());
        $this->assertSame(53, (int) $invoice->coverage_rate);
    }

    public function test_an_unknown_organisation_leaves_everything_to_the_patient(): void
    {
        $invoice = $this->bill(['insurer' => 'Mutuelle inconnue', 'rate' => 50]);

        // On ne refuse pas la vente, mais on n'invente pas un tiers payant
        // que Finance ne connaît pas : le patient doit tout.
        $this->assertNull($invoice->insurer_id);
        $this->assertSame(2_100, $invoice->patientDue());
    }

    public function test_the_queue_shows_the_reason_and_the_amount_already_billed(): void
    {
        $invoice = $this->bill();
        $cashier = $this->cashier();
        $this->openSession($cashier);

        $this->queue->addBilled('20', '7-20-3-x', 3, 'Sylla Baba', $invoice->id, $invoice->patientDue(), 'Dispensation DIS-2026-000001');

        $this->actingAs($cashier)->get(route('finance.queue.index', ['file' => '20']))
            ->assertOk()
            ->assertSee('Dispensation DIS-2026-000001')
            ->assertSee('déjà facturé');
    }

    public function test_collecting_settles_the_invoice_and_advances_the_visit(): void
    {
        $invoice = $this->bill();
        $cashier = $this->cashier();
        $session = $this->openSession($cashier);

        $this->queue->addBilled('20', '7-20-3-x', 3, 'Sylla Baba', $invoice->id, $invoice->patientDue(), 'Dispensation DIS-2026-000001');

        $this->actingAs($cashier)->post(route('finance.cash.payments.store', $session), [
            'payment_method_id' => $this->cashMethod()->id,
            'invoice_id' => $invoice->id,
            'amount' => '2 100',
            'patient_id' => 'PAT-00003',
            'patient_name' => 'Sylla Baba',
            'queue_ref' => '20',
            'visit_ref' => '7-20-3-x',
        ])->assertSessionHas('finance_status');

        $payment = Payment::query()->sole();

        $this->assertSame($invoice->id, $payment->invoice_id);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->refresh()->status);
        $this->assertSame(0, $invoice->balance());
        $this->assertCount(1, $this->advancer->advanced);
    }

    public function test_a_fully_covered_patient_passes_without_a_payment(): void
    {
        Insurer::create(['code' => 'AMO', 'name' => 'AMO', 'kind' => Insurer::KIND_INSURANCE, 'default_rate' => 100, 'is_active' => true]);

        $invoice = $this->bill(['insurer' => 'AMO', 'rate' => 100]);

        $this->assertSame(0, $invoice->patientDue());

        $cashier = $this->cashier();
        $this->openSession($cashier);
        $this->queue->addBilled('20', '7-20-3-x', 3, 'Sylla Baba', $invoice->id, 0, 'Dispensation DIS-2026-000001');

        $this->actingAs($cashier)->get(route('finance.queue.index', ['file' => '20']))
            ->assertOk()
            ->assertSee('Rien à encaisser');

        $this->post(route('finance.queue.release'), ['file' => '20', 'visite' => '7-20-3-x'])
            ->assertSessionHas('finance_status');

        // Rien dans le tiroir, mais le patient poursuit son parcours, et le
        // journal dit qui l'a laissé passer.
        $this->assertSame(0, Payment::count());
        $this->assertCount(1, $this->advancer->advanced);
        $this->assertSame(0, $this->advancer->advanced[0]['payment']->amount);
        $this->assertSame(1, AuditLog::where('event', 'queued_visit_released')->count());
    }

    public function test_a_patient_who_still_owes_does_not_pass(): void
    {
        $invoice = $this->bill();
        $cashier = $this->cashier();
        $this->openSession($cashier);
        $this->queue->addBilled('20', '7-20-3-x', 3, 'Sylla Baba', $invoice->id, $invoice->patientDue(), 'Dispensation DIS-2026-000001');

        $this->actingAs($cashier)
            ->post(route('finance.queue.release'), ['file' => '20', 'visite' => '7-20-3-x'])
            ->assertSessionHas('finance_error');

        $this->assertSame([], $this->advancer->advanced);
    }

    /**
     * @param  array{insurer?: ?string, rate?: ?int, reference?: ?string}|null  $coverage
     */
    private function bill(?array $coverage = null): Invoice
    {
        return app(BillExternalSale::class)->handle(
            BillExternalSale::SOURCE_PHARMACIE,
            'DIS-2026-000001',
            'PAT-00003',
            'Sylla Baba',
            [
                ['label' => 'Amoxicilline 500 mg', 'quantity' => 14, 'unit_price' => 100],
                ['label' => 'Paracétamol 1 g', 'quantity' => 7, 'unit_price' => 100],
            ],
            $this->pharmacist(),
            $coverage,
        );
    }

    private function pharmacist(): TestUser
    {
        return $this->cashier();
    }
}
