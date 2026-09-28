<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Tests\Feature\Http;

use Keneya\Pharmacie\Actions\SendToCashier;
use Keneya\Pharmacie\Contracts\InsurerDirectory;
use Keneya\Pharmacie\Contracts\SaleSink;
use Keneya\Pharmacie\Coverage\HostInsurer;
use Keneya\Pharmacie\Models\Dispensation;
use Keneya\Pharmacie\Models\DispensationItem;
use Keneya\Pharmacie\Models\Product;
use Keneya\Pharmacie\Models\ProductCoverage;
use Keneya\Pharmacie\Support\Rbac;
use Keneya\Pharmacie\Tests\Support\FakeSaleSink;
use Keneya\Pharmacie\Tests\Support\StockFixtures;
use Keneya\Pharmacie\Tests\TestCase;

/**
 * Assurances et aides sociales sur les produits.
 *
 * La liste des organismes appartient a la caisse ; ce qu'ils couvrent parmi
 * les medicaments appartient a la pharmacie. Et rien ne s'applique tout
 * seul : au comptoir, le preparateur choisit.
 */
class ProductCoverageHttpTest extends TestCase
{
    use StockFixtures;

    private FakeSaleSink $caisse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('pharmacie:sync-permissions')->assertSuccessful();

        $this->app->instance(InsurerDirectory::class, new FakeInsurers);
        $this->caisse = new FakeSaleSink('FAC-2026-000001');
        $this->app->instance(SaleSink::class, $this->caisse);

        config(['pharmacie.dispensing.payment_before_delivery' => false]);
    }

    public function test_la_fiche_produit_declare_une_prise_en_charge(): void
    {
        $product = $this->makeProduct(['name' => 'Amoxicilline 500 mg']);

        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->get(route('pharmacie.catalog.products.show', $product))
            ->assertOk()
            ->assertSee('Prise en charge')
            ->assertSee('AMO');

        $this->post(route('pharmacie.catalog.products.coverage.store', $product), [
            'insurer_ref' => 'amo',
            'rate' => '80',
        ])->assertSessionHas('pharmacie_status');

        $coverage = ProductCoverage::query()->sole();

        $this->assertSame('amo', $coverage->insurer_ref);
        // Le nom est recopie : la regle reste lisible si la caisse le renomme.
        $this->assertSame('AMO', $coverage->insurer_name);
        $this->assertSame(80, $coverage->rate);
    }

    public function test_un_organisme_inconnu_de_l_hote_est_refuse(): void
    {
        $product = $this->makeProduct();

        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->from(route('pharmacie.catalog.products.show', $product))
            ->post(route('pharmacie.catalog.products.coverage.store', $product), [
                'insurer_ref' => 'mutuelle-fantome',
                'rate' => '50',
            ])
            ->assertSessionHas('pharmacie_error');

        $this->assertSame(0, ProductCoverage::query()->count());
    }

    public function test_une_prise_en_charge_se_retire(): void
    {
        $product = $this->makeProduct();
        $coverage = $this->couvrir($product, 'amo', 'AMO', 80);

        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->post(route('pharmacie.catalog.products.coverage.destroy', [$product, $coverage]))
            ->assertSessionHas('pharmacie_status');

        $this->assertSame(0, ProductCoverage::query()->count());
    }

    public function test_le_comptoir_applique_le_taux_de_chaque_produit(): void
    {
        $location = $this->makeLocation();

        $couvert = $this->makeProduct(['name' => 'Amoxicilline 500 mg', 'sale_price' => 100]);
        $nu = $this->makeProduct(['name' => 'Sirop contre la toux', 'sale_price' => 100]);

        $this->stockUp($this->makeBatch($couvert, 'LOT-A', now()->addYear()->toDateString()), 50, $location);
        $this->stockUp($this->makeBatch($nu, 'LOT-B', now()->addYear()->toDateString()), 50, $location);

        // L'organisme couvre l'amoxicilline, et pas le sirop.
        $this->couvrir($couvert, 'amo', 'AMO', 80);

        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->post(route('pharmacie.dispensing.store'), [
                'location_id' => $location->id,
                'patient_name' => 'Aminata Traoré',
                'coverage_insurer_ref' => 'amo',
                'lines' => [
                    ['product_id' => $couvert->id, 'quantity' => '10'],
                    ['product_id' => $nu->id, 'quantity' => '4'],
                ],
            ])
            ->assertSessionHas('pharmacie_status');

        $lignes = DispensationItem::query()->orderBy('id')->get();

        $this->assertSame(80, (int) $lignes[0]->insurer_rate);
        $this->assertSame(0, (int) $lignes[1]->insurer_rate);

        // Servie d'abord, facturee ensuite : c'est l'envoi a la caisse qui
        // transporte les taux.
        $this->post(route('pharmacie.dispensing.bill', Dispensation::query()->sole()), [
            'kind' => SendToCashier::KIND_COVERAGE,
        ])->assertSessionHas('pharmacie_status');

        $vente = $this->caisse->sales[0];

        $this->assertSame(80, $vente->lines[0]['insurer_rate']);
        $this->assertSame(0, $vente->lines[1]['insurer_rate']);
        $this->assertSame('AMO', $vente->coverage['insurer']);
    }

    public function test_sans_choix_rien_n_est_pris_en_charge(): void
    {
        $location = $this->makeLocation();
        $product = $this->makeProduct(['sale_price' => 100]);
        $this->stockUp($this->makeBatch($product, 'LOT-A', now()->addYear()->toDateString()), 50, $location);

        // Le produit est couvert, mais le comptoir n'a choisi personne :
        // appliquer le taux sans qu'on l'ait voulu creerait une creance
        // qu'aucun organisme ne reconnaitrait.
        $this->couvrir($product, 'amo', 'AMO', 80);

        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->post(route('pharmacie.dispensing.store'), [
                'location_id' => $location->id,
                'patient_name' => 'Aminata Traoré',
                'lines' => [['product_id' => $product->id, 'quantity' => '10']],
            ])
            ->assertSessionHas('pharmacie_status');

        $this->assertSame(0, (int) DispensationItem::query()->sole()->insurer_rate);
        $this->assertNull(Dispensation::query()->sole()->coverage_insurer);
    }

    public function test_le_comptoir_annonce_ce_qui_est_couvert_avant_le_choix(): void
    {
        $location = $this->makeLocation();
        $product = $this->makeProduct(['name' => 'Amoxicilline 500 mg']);
        $this->stockUp($this->makeBatch($product, 'LOT-A', now()->addYear()->toDateString()), 50, $location);
        $this->couvrir($product, 'amo', 'AMO', 80);

        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->get(route('pharmacie.dispensing.create'))
            ->assertOk()
            ->assertSee('Aucune : le patient paie tout')
            ->assertSee('AMO');
    }

    private function couvrir(Product $product, string $ref, string $name, int $rate): ProductCoverage
    {
        return ProductCoverage::create([
            'facility_id' => 1,
            'product_id' => $product->id,
            'insurer_ref' => $ref,
            'insurer_name' => $name,
            'rate' => $rate,
        ]);
    }
}

/**
 * Un annuaire d'organismes d'hote, en memoire.
 */
final class FakeInsurers implements InsurerDirectory
{
    public function insurers(): array
    {
        return [
            new HostInsurer('amo', 'AMO', HostInsurer::KIND_INSURANCE, 70),
            new HostInsurer('indigents', 'Fonds des indigents', HostInsurer::KIND_SOCIAL_AID, 100),
        ];
    }
}
