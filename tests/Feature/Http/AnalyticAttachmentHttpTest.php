<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature\Http;

use Illuminate\Support\Carbon;
use Keneya\FinanceCaisse\Actions\CreateInvoice;
use Keneya\FinanceCaisse\Actions\RecordDisbursement;
use Keneya\FinanceCaisse\Actions\RecordPayment;
use Keneya\FinanceCaisse\Models\Act;
use Keneya\FinanceCaisse\Models\AnalyticCenter;
use Keneya\FinanceCaisse\Models\AuditLog;
use Keneya\FinanceCaisse\Models\CashSession;
use Keneya\FinanceCaisse\Models\Disbursement;
use Keneya\FinanceCaisse\Models\Insurer;
use Keneya\FinanceCaisse\Models\Payment;
use Keneya\FinanceCaisse\Services\AnalyticResult;
use Keneya\FinanceCaisse\Support\LedgerFilters;
use Keneya\FinanceCaisse\Tests\Support\CatalogFixtures;
use Keneya\FinanceCaisse\Tests\Support\TestUser;

/**
 * Le rattachement analytique fin : chaque écriture porte son centre, les
 * dépenses aussi, un pôle totalise ce que ses services portent, et le
 * catalogue ne réécrit pas le passé.
 */
class AnalyticAttachmentHttpTest extends HttpTestCase
{
    use CatalogFixtures;

    private function center(string $code, string $name, string $kind = AnalyticCenter::KIND_REVENUE, ?AnalyticCenter $parent = null): AnalyticCenter
    {
        return AnalyticCenter::create([
            'code' => $code,
            'name' => $name,
            'kind' => $kind,
            'parent_id' => $parent?->id,
            'is_active' => true,
        ]);
    }

    private function act(string $code, string $name, ?AnalyticCenter $center): Act
    {
        return Act::create([
            'code' => $code,
            'name' => $name,
            'analytic_center_id' => $center?->id,
            'is_active' => true,
        ]);
    }

    private function caisse(TestUser $cashier): CashSession
    {
        return $this->openSession($cashier, 10_000, $this->makeRegister('CAISSE-TICKET'));
    }

    public function test_a_payment_keeps_the_center_it_had_when_the_act_moves(): void
    {
        $labo = $this->center('LABO', 'Laboratoire');
        $imagerie = $this->center('IMAGERIE', 'Imagerie');
        $acte = $this->act('NFS', 'Numeration', $labo);

        $cashier = $this->cashier();
        $session = $this->caisse($cashier);

        $avant = app(RecordPayment::class)->handle($session, $this->cashMethod(), 3_000, $cashier, ['act_id' => $acte->id]);
        $this->assertSame($labo->id, $avant->analytic_center_id);

        // L'administrateur rattache l'acte ailleurs.
        $this->actingAs($this->admin())
            ->post(route('finance.catalog.acts.center', $acte), ['analytic_center_id' => $imagerie->id])
            ->assertRedirect(route('finance.catalog.acts.show', $acte))
            ->assertSessionHas('finance_status');

        $apres = app(RecordPayment::class)->handle($session, $this->cashMethod(), 5_000, $cashier, ['act_id' => $acte->id]);

        // Le passé ne bouge pas, le nouveau suit le nouveau rattachement.
        $this->assertSame($labo->id, $avant->refresh()->analytic_center_id);
        $this->assertSame($imagerie->id, $apres->analytic_center_id);
        $this->assertSame(1, AuditLog::where('event', 'act_center_set')->count());

        $this->actingAs($cashier)->get('/finance/recettes?centre='.$labo->id)
            ->assertSee('3 000 FCFA')
            ->assertDontSee('5 000 FCFA');
    }

    public function test_an_act_cannot_be_attached_to_a_charges_only_center(): void
    {
        $charges = $this->center('LOGISTIQUE', 'Logistique', AnalyticCenter::KIND_COST);
        $acte = $this->act('NFS', 'Numeration', null);

        $this->actingAs($this->admin())->from('/finance/catalogue/actes/'.$acte->id)
            ->post(route('finance.catalog.acts.center', $acte), ['analytic_center_id' => $charges->id])
            ->assertSessionHas('finance_error');

        $this->assertNull($acte->refresh()->analytic_center_id);
    }

    public function test_a_disbursement_carries_its_charges_center_and_is_read_back_by_center(): void
    {
        $logistique = $this->center('LOGISTIQUE', 'Logistique', AnalyticCenter::KIND_COST);
        $labo = $this->center('LABO', 'Laboratoire');

        $cashier = $this->cashier();
        $session = $this->caisse($cashier);
        app(RecordPayment::class)->handle($session, $this->cashMethod(), 20_000, $cashier);

        $this->actingAs($cashier)
            ->post(route('finance.cash.disbursements.store', $session), [
                'payment_method_id' => $this->cashMethod()->id,
                'amount' => '4 000',
                'reason' => 'Achat de fournitures',
                'category' => 'fournitures',
                'analytic_center_id' => $logistique->id,
            ])
            ->assertRedirect(route('finance.cash.sessions.show', $session));

        $this->assertSame($logistique->id, Disbursement::sole()->analytic_center_id);

        $this->actingAs($cashier)->get('/finance/depenses')
            ->assertOk()
            ->assertSee('Par centre analytique')
            ->assertSee('Logistique');

        $this->get('/finance/depenses?centre='.$logistique->id)->assertSee('4 000 FCFA');
        $this->get('/finance/depenses?centre='.$labo->id)->assertDontSee('Achat de fournitures');

        // Un centre de produits ne porte pas une charge.
        $this->from(route('finance.cash.sessions.show', $session))
            ->post(route('finance.cash.disbursements.store', $session), [
                'payment_method_id' => $this->cashMethod()->id,
                'amount' => '1 000',
                'reason' => 'Réactifs',
                'analytic_center_id' => $labo->id,
            ])
            ->assertSessionHas('finance_error');

        $this->assertSame(1, Disbursement::count());
    }

    public function test_a_pole_totals_what_its_services_carry(): void
    {
        $pole = $this->center('PLATEAU', 'Plateau technique');
        $labo = $this->center('LABO', 'Laboratoire', AnalyticCenter::KIND_REVENUE, $pole);
        $imagerie = $this->center('IMAGERIE', 'Imagerie', AnalyticCenter::KIND_BOTH, $pole);

        $cashier = $this->cashier();
        $session = $this->caisse($cashier);

        app(RecordPayment::class)->handle($session, $this->cashMethod(), 3_000, $cashier, ['act_id' => $this->act('NFS', 'Numeration', $labo)->id]);
        app(RecordPayment::class)->handle($session, $this->cashMethod(), 7_000, $cashier, ['act_id' => $this->act('ECHO', 'Echographie', $imagerie)->id]);
        app(RecordDisbursement::class)->handle($session, $this->cashMethod(), 2_000, 'Films', $cashier, ['analytic_center_id' => $imagerie->id]);

        // Le pôle porte ce que ses deux services portent.
        $this->actingAs($this->accountant())->get('/finance/recettes?centre='.$pole->id)
            ->assertSee('10 000 FCFA');

        $rapport = $this->get('/finance/rapports?type=resultat-centre')->assertOk();

        $rapport->assertSee('Plateau technique')
            ->assertSee('Laboratoire')
            ->assertSee('Imagerie')
            ->assertSee('8 000 FCFA');  // resultat du pole : 10 000 - 2 000

        // Le total ne compte pas deux fois ce que le pôle partage avec ses services.
        $csv = $this->get('/finance/rapports/export?type=resultat-centre')->streamedContent();
        $this->assertStringContainsString('Total;10000;0;2000;8000', $csv);
    }

    public function test_a_center_is_renamed_reattached_and_never_becomes_its_own_parent(): void
    {
        $pole = $this->center('PLATEAU', 'Plateau technique');
        $labo = $this->center('LABO', 'Laboratoire', AnalyticCenter::KIND_REVENUE, $pole);

        $this->actingAs($this->admin())
            ->post(route('finance.catalog.centers.update', $labo), [
                'name' => 'Laboratoire central',
                'parent_id' => '',
                'kind' => AnalyticCenter::KIND_BOTH,
            ])
            ->assertRedirect(route('finance.catalog.centers.index'));

        $labo->refresh();
        $this->assertSame('Laboratoire central', $labo->name);
        $this->assertNull($labo->parent_id);
        $this->assertSame(AnalyticCenter::KIND_BOTH, $labo->kind);
        $this->assertSame(1, AuditLog::where('event', 'analytic_center_updated')->count());

        // Une boucle de parenté est refusée.
        $labo->update(['parent_id' => $pole->id]);

        $this->from('/finance/catalogue/centres')
            ->post(route('finance.catalog.centers.update', $pole), [
                'name' => 'Plateau technique',
                'parent_id' => (string) $labo->id,
                'kind' => AnalyticCenter::KIND_REVENUE,
            ])
            ->assertSessionHas('finance_error');

        $this->assertNull($pole->refresh()->parent_id);

        // Un centre qui porte des actes ne devient pas un centre de charges.
        $this->act('NFS', 'Numeration', $labo);

        $this->from('/finance/catalogue/centres')
            ->post(route('finance.catalog.centers.update', $labo), [
                'name' => 'Laboratoire central',
                'parent_id' => (string) $pole->id,
                'kind' => AnalyticCenter::KIND_COST,
            ])
            ->assertSessionHas('finance_error');

        $this->assertSame(AnalyticCenter::KIND_BOTH, $labo->refresh()->kind);
    }

    public function test_an_insurer_settlement_is_split_between_the_centers_of_the_invoice(): void
    {
        $labo = $this->center('LABO', 'Laboratoire');
        $imagerie = $this->center('IMAGERIE', 'Imagerie');

        $nfs = $this->act('NFS', 'Numeration', $labo);
        $echo = $this->act('ECHO', 'Echographie', $imagerie);
        $this->setTariff($nfs, 2_000);
        $this->setTariff($echo, 8_000);

        $insurer = Insurer::create([
            'code' => 'AMO', 'name' => 'AMO', 'kind' => Insurer::KIND_INSURANCE,
            'default_rate' => 50, 'coverage_scope' => Insurer::SCOPE_ALL, 'is_active' => true,
        ]);

        // Facture prise en charge a 50 % : 1 000 pour le labo, 4 000 pour l'imagerie.
        $invoice = app(CreateInvoice::class)->handle(
            'PAT-00001',
            'Aminata Traore',
            [['act_id' => $nfs->id, 'quantity' => 1], ['act_id' => $echo->id, 'quantity' => 1]],
            null,
            $this->makeUser(),
            ['insurer_id' => $insurer->id],
        );

        $this->assertSame(5_000, $invoice->insurer_share);
        $this->assertSame([$labo->id, $imagerie->id], $invoice->lines->pluck('analytic_center_id')->all());

        // L'assureur regle la moitie de sa part : elle se repartit au prorata.
        $this->actingAs($this->accountant())
            ->post(route('finance.insurance.settle', $invoice), ['amount' => '2 500'])
            ->assertRedirect();

        $result = app(AnalyticResult::class)->build(new LedgerFilters(
            from: Carbon::today()->startOfDay(),
            to: Carbon::today()->endOfDay(),
        ));

        $settlements = collect($result['rows'])->mapWithKeys(
            static fn (array $row): array => [trim($row['label'], " \u{a0}└ ") => $row['settlements']],
        );

        $this->assertSame(500, $settlements->get('Laboratoire'));
        $this->assertSame(2_000, $settlements->get('Imagerie'));
        $this->assertSame(2_500, $result['total']['settlements']);
    }

    public function test_a_payment_outside_the_catalogue_stays_visible_as_unattached(): void
    {
        $cashier = $this->cashier();
        $session = $this->caisse($cashier);
        app(RecordPayment::class)->handle($session, $this->cashMethod(), 1_500, $cashier, ['description' => 'Avance']);

        $this->assertNull(Payment::sole()->analytic_center_id);

        $this->actingAs($cashier)->get('/finance/recettes')->assertSee('Hors catalogue');
    }
}
