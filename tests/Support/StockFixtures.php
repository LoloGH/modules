<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Tests\Support;

use Keneya\Pharmacie\Models\Batch;
use Keneya\Pharmacie\Models\Location;
use Keneya\Pharmacie\Models\Product;
use Keneya\Pharmacie\Models\StockMovement;
use Keneya\Pharmacie\Services\StockLedger;

/**
 * De quoi monter un stock de test en deux lignes : un produit, un lot, un
 * emplacement, et des unités entrées par le grand livre — jamais écrites à la
 * main, pour que les tests éprouvent le vrai chemin.
 */
trait StockFixtures
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeProduct(array $attributes = []): Product
    {
        static $sequence = 0;
        $sequence++;

        return Product::create($attributes + [
            'facility_id' => 1,
            'code' => 'PROD'.$sequence,
            'name' => 'Produit '.$sequence,
            'kind' => Product::KIND_MEDICINE,
            'unit' => 'unité',
            'min_threshold' => 10,
            'sale_price' => 1_000,
            'is_active' => true,
        ]);
    }

    protected function makeLocation(string $code = 'CENTRALE', string $name = 'Pharmacie centrale'): Location
    {
        return Location::firstOrCreate(
            ['facility_id' => 1, 'code' => $code],
            ['name' => $name, 'kind' => Location::KIND_PHARMACY, 'is_active' => true],
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeBatch(Product $product, string $number, ?string $expiresOn = null, array $attributes = []): Batch
    {
        return Batch::create($attributes + [
            'facility_id' => 1,
            'product_id' => $product->id,
            'number' => $number,
            'received_on' => now()->toDateString(),
            'expires_on' => $expiresOn,
            'purchase_price' => 500,
            'sale_price' => 1_000,
            'status' => Batch::STATUS_ACTIVE,
        ]);
    }

    /**
     * Fait entrer des unités par le grand livre, comme une réception.
     */
    protected function stockUp(Batch $batch, int $quantity, ?Location $location = null): Batch
    {
        app(StockLedger::class)->receive(
            $batch,
            $location ?? $this->makeLocation(),
            $quantity,
            StockMovement::KIND_RECEPTION,
            $this->makeUser(),
            ['reason' => 'Mise en place du test'],
        );

        return $batch->refresh();
    }
}
