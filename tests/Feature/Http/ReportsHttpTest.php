<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature\Http;

use Illuminate\Support\Carbon;
use Keneya\FinanceCaisse\Actions\CancelCashMovement;
use Keneya\FinanceCaisse\Actions\CreateInvoice;
use Keneya\FinanceCaisse\Actions\RecordDisbursement;
use Keneya\FinanceCaisse\Actions\RecordInsuranceSettlement;
use Keneya\FinanceCaisse\Actions\RecordPayment;
use Keneya\FinanceCaisse\Models\AnalyticCenter;
use Keneya\FinanceCaisse\Models\Insurer;
use Keneya\FinanceCaisse\Tests\Support\CatalogFixtures;

/**
 * Rapports : chiffres réels (valides), filtres, types, export CSV, droits.
 */
class ReportsHttpTest extends HttpTestCase
{
    use CatalogFixtures;

    /**
     * Imagerie 7 500 (espèces) + Laboratoire 3 000 (Mobile Money) + une
     * avance 1 000 hors catalogue ; un encaissement annulé ; deux dépenses ;
     * un règlement d'assureur de 4 000.
     */
    private function seedMovements(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier, 50_000);
        $echo = $this->makeAct('ECHO', $this->makeCenter('IMAGERIE'));
        $nfs = $this->makeAct('NFS', $this->makeCenter('LABORATOIRE'));

        $pay = app(RecordPayment::class);
        $pay->handle($session, $this->cashMethod(), 7_500, $cashier, ['act_id' => $echo->id]);
        $pay->handle($session, $this->momoMethod(), 3_000, $cashier, ['act_id' => $nfs->id, 'reference' => 'OM-1']);
        $pay->handle($session, $this->cashMethod(), 1_000, $cashier, ['description' => 'Avance']);
        $cancelled = $pay->handle($session, $this->cashMethod(), 9_999, $cashier, ['act_id' => $echo->id]);
        app(CancelCashMovement::class)->payment($cancelled, 'Erreur', $cashier);

        $out = app(RecordDisbursement::class);
        $out->handle($session, $this->cashMethod(), 2_000, 'Carburant', $cashier, ['category' => 'carburant']);
        $out->handle($session, $this->cashMethod(), 500, 'Papier', $cashier, ['category' => 'fournitures']);

        $insurer = Insurer::create(['code' => 'INPS', 'name' => 'INPS', 'default_rate' => 80, 'is_active' => true]);
        $act = $this->makeAct('CONS');
        $this->setTariff($act, 5_000);
        $invoice = app(CreateInvoice::class)->handle(null, 'Awa Keita', [['act_id' => $act->id, 'quantity' => 1]], null, $cashier,
            ['insurer_id' => $insurer->id]);
        app(RecordInsuranceSettlement::class)->handle($invoice, 4_000, 'VIR-1', null, $this->makeUser());
    }

    public function test_the_default_report_is_revenue_by_service_with_the_period_figures(): void
    {
        $this->seedMovements();

        $this->actingAs($this->accountant())->get('/finance/rapports')
            ->assertOk()
            ->assertSee('Recettes par service (centre analytique)')
            ->assertSee('Imagerie')
            ->assertSee('7 500 FCFA')
            ->assertSee('Laboratoire')
            ->assertSee('Hors catalogue')
            ->assertSee('11 500 FCFA')          // recettes valides
            ->assertDontSee('9 999')
            ->assertSee('Règlements des assureurs')
            ->assertSee('4 000 FCFA')
            ->assertSee('2 500 FCFA')           // dépenses
            ->assertSee('13 000 FCFA')          // solde : 11 500 + 4 000 − 2 500
            ->assertSee('Exporter (CSV)');
    }

    public function test_each_report_type(): void
    {
        $this->seedMovements();
        $this->actingAs($this->accountant());

        $this->get('/finance/rapports?type=recettes-moyen')->assertSee('Mobile')->assertSee('3 000 FCFA');
        $this->get('/finance/rapports?type=recettes-acte')->assertSee('Acte ECHO')->assertSee('Acte NFS');
        $this->get('/finance/rapports?type=depenses-categorie')->assertSee('Carburant et transport')->assertSee('2 000 FCFA');
        $this->get('/finance/rapports?type=depenses-moyen')->assertSee('2 500 FCFA');
        $this->get('/finance/rapports?type=reglements-assurance')->assertSee('INPS')->assertSee('4 000 FCFA');
        $this->get('/finance/rapports?type=prises-en-charge-organisme')
            ->assertSee('INPS (Assurance)')->assertSee('Pris en charge')->assertSee('Reste dû');
        $this->get('/finance/rapports?type=prises-en-charge-acte')->assertSee('Acte CONS')->assertSee('4 000 FCFA');
        $this->get('/finance/rapports?type=synthese')
            ->assertSee('Recettes caisse')
            ->assertSee('Règlements assurance')
            ->assertSee(Carbon::today()->format('d/m/Y'));

        // Un type inconnu retombe sur le rapport par défaut.
        $this->get('/finance/rapports?type=inconnu')->assertOk()->assertSee('Recettes par service');
    }

    public function test_filters_narrow_the_revenue(): void
    {
        $this->seedMovements();
        $this->actingAs($this->accountant());

        $labo = AnalyticCenter::where('code', 'LABORATOIRE')->sole();

        $this->get('/finance/rapports?centre='.$labo->id)
            ->assertSee('Laboratoire')
            ->assertDontSee('Hors catalogue');

        $this->get('/finance/rapports?du='.Carbon::today()->subMonths(3)->toDateString().'&au='.Carbon::today()->subMonths(2)->toDateString())
            ->assertSee('Rien sur cette période');
    }

    public function test_the_csv_export(): void
    {
        $this->seedMovements();

        $response = $this->actingAs($this->accountant())->get('/finance/rapports/export?type=recettes-centre');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename=rapport-recettes-centre-', (string) $response->headers->get('Content-Disposition'));

        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('"Service (centre analytique)";Nombre;Montant', $csv);
        $this->assertStringContainsString('Imagerie;1;7500', $csv);
        $this->assertStringContainsString('Total;3;11500', $csv);
    }

    public function test_rights(): void
    {
        $this->actingAs($this->cashier())->get('/finance/rapports')->assertForbidden();
        $this->actingAs($this->cashier())->get('/finance/rapports/export')->assertForbidden();
        $this->actingAs($this->accountant())->get('/finance/rapports')->assertOk();
    }
}
