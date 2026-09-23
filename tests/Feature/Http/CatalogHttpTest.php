<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Tests\Feature\Http;

use Keneya\Pharmacie\Models\AuditLog;
use Keneya\Pharmacie\Models\Category;
use Keneya\Pharmacie\Models\Product;
use Keneya\Pharmacie\Support\Rbac;
use Keneya\Pharmacie\Tests\TestCase;

/**
 * Le catalogue : ce que la pharmacie sait référencer, et qui a le droit de le
 * décrire, de le tarifer ou seulement de le lire.
 */
class CatalogHttpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('pharmacie:sync-permissions')->assertSuccessful();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function product(array $attributes = []): Product
    {
        return Product::create($attributes + [
            'facility_id' => 1,
            'code' => 'AMOX500',
            'name' => 'Amoxicilline',
            'dci' => 'Amoxicilline',
            'kind' => Product::KIND_MEDICINE,
            'form' => 'Gélule',
            'dosage' => '500 mg',
            'unit' => 'gélule',
            'min_threshold' => 50,
            'is_active' => true,
        ]);
    }

    public function test_a_product_is_referenced_with_what_avoids_dispensing_the_wrong_one(): void
    {
        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->post(route('pharmacie.catalog.products.store'), [
                'code' => 'amox500',
                'name' => 'Amoxicilline',
                'dci' => 'Amoxicilline',
                'kind' => Product::KIND_MEDICINE,
                'form' => 'Gélule',
                'dosage' => '500 mg',
                'route' => 'Orale',
                'unit' => 'gélule',
                'packaging' => 'Boîte de 12',
                'min_threshold' => '50',
                'sale_price' => '1 500',
            ])
            ->assertSessionHas('pharmacie_status');

        $product = Product::sole();

        $this->assertSame('AMOX500', $product->code);
        $this->assertSame(1_500, $product->sale_price);
        $this->assertSame('Amoxicilline 500 mg (Gélule)', $product->label());
        $this->assertSame(1, AuditLog::where('event', 'product_created')->count());

        // Le code ne se reprend pas dans le meme etablissement.
        $this->post(route('pharmacie.catalog.products.store'), [
            'code' => 'AMOX500', 'name' => 'Autre', 'kind' => Product::KIND_MEDICINE,
            'unit' => 'gélule', 'min_threshold' => '0',
        ])->assertSessionHasErrors('code');
    }

    public function test_the_counter_finds_a_product_by_name_dci_code_or_barcode(): void
    {
        $this->product(['barcode' => '3401579876543', 'brand_name' => 'Clamoxyl']);
        $this->product(['code' => 'PARA500', 'name' => 'Paracétamol', 'dci' => 'Paracétamol', 'barcode' => null]);

        $pharmacist = $this->userWithRole(Rbac::ROLE_PHARMACIST);

        $this->actingAs($pharmacist)->get('/pharmacie/catalogue/produits?q=Clamoxyl')
            ->assertOk()->assertSee('Amoxicilline')->assertDontSee('Paracétamol');

        $this->get('/pharmacie/catalogue/produits?q=3401579876543')->assertSee('Amoxicilline');
        $this->get('/pharmacie/catalogue/produits?q=PARA500')->assertSee('Paracétamol')->assertDontSee('Clamoxyl');
        $this->get('/pharmacie/catalogue/produits?q=introuvable')->assertSee('Aucun produit');
    }

    public function test_a_product_is_deactivated_never_deleted(): void
    {
        $product = $this->product();

        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->post(route('pharmacie.catalog.products.toggle', $product))
            ->assertRedirect(route('pharmacie.catalog.products.index'));

        $this->assertFalse($product->refresh()->is_active);
        $this->assertSame(1, Product::count());
        $this->assertSame(1, AuditLog::where('event', 'product_deactivated')->count());

        // Il reste lisible, sous le filtre des desactives.
        $this->get('/pharmacie/catalogue/produits?statut=inactifs')->assertSee('Amoxicilline');
    }

    public function test_setting_a_price_is_a_right_of_its_own(): void
    {
        $product = $this->product(['sale_price' => 1_000]);

        // Le magasinier decrit le produit, il ne fixe pas son prix.
        $this->actingAs($this->userWithRole(Rbac::ROLE_STOREKEEPER))
            ->post(route('pharmacie.catalog.products.update', $product), [
                'code' => 'AMOX500', 'name' => 'Amoxicilline', 'kind' => Product::KIND_MEDICINE,
                'unit' => 'gélule', 'min_threshold' => '50', 'sale_price' => '9 000',
            ])
            ->assertForbidden();

        // Le pharmacien, lui, le peut.
        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->post(route('pharmacie.catalog.products.update', $product), [
                'code' => 'AMOX500', 'name' => 'Amoxicilline', 'kind' => Product::KIND_MEDICINE,
                'unit' => 'gélule', 'min_threshold' => '50', 'sale_price' => '9 000',
            ])
            ->assertSessionHas('pharmacie_status');

        $this->assertSame(9_000, $product->refresh()->sale_price);
    }

    public function test_a_category_holding_active_products_is_not_deactivated(): void
    {
        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->post(route('pharmacie.catalog.categories.store'), ['code' => 'antibio', 'name' => 'Antibiotiques'])
            ->assertSessionHas('pharmacie_status');

        $category = Category::sole();
        $this->assertSame('ANTIBIO', $category->code);

        $product = $this->product(['category_id' => $category->id]);

        $this->from('/pharmacie/catalogue/categories')
            ->post(route('pharmacie.catalog.categories.toggle', $category))
            ->assertSessionHas('pharmacie_error');

        $this->assertTrue($category->refresh()->is_active);

        // Le produit desactive, la categorie peut l'etre a son tour.
        $product->update(['is_active' => false]);

        $this->post(route('pharmacie.catalog.categories.toggle', $category))->assertSessionHas('pharmacie_status');
        $this->assertFalse($category->refresh()->is_active);
    }

    public function test_reading_the_catalogue_and_describing_it_are_two_rights(): void
    {
        $product = $this->product();

        // Le preparateur consulte, il ne reference pas.
        $this->actingAs($this->userWithRole(Rbac::ROLE_DISPENSER))
            ->get('/pharmacie/catalogue/produits')->assertOk()->assertDontSee('Référencer un produit');

        $this->actingAs($this->userWithRole(Rbac::ROLE_DISPENSER))
            ->post(route('pharmacie.catalog.products.toggle', $product))->assertForbidden();

        // Le menu ne montre le catalogue qu'a qui peut l'ouvrir.
        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))->get('/pharmacie')
            ->assertSee(route('pharmacie.catalog.products.index'), false);
    }
}
