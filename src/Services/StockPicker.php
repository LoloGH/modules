<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Services;

use Keneya\Pharmacie\Models\Batch;
use Keneya\Pharmacie\Models\Location;
use Keneya\Pharmacie\Models\Product;
use Keneya\Pharmacie\Models\Stock;

/**
 * Le choix des lots : **FEFO, First Expired, First Out**.
 *
 * Quand un produit existe en plusieurs lots, celui qui périme le premier sort
 * le premier. Le comptoir n'a pas à chercher : le système propose, et le
 * pharmacien peut toujours choisir autrement, mais alors c'est tracé, avec un
 * motif (voir la dispensation).
 *
 * Ne sont jamais proposés : les lots périmés, bloqués ou détruits, et les lots
 * dont il ne reste rien de disponible.
 */
final class StockPicker
{
    /**
     * Les lots servables d'un produit, dans l'ordre FEFO.
     *
     * @return list<array{batch: Batch, available: int}>
     */
    public function batchesFor(Product $product, Location $location): array
    {
        $stocks = Stock::query()
            ->where('product_id', $product->getKey())
            ->where('location_id', $location->getKey())
            ->inStock()
            ->with('batch')
            ->get();

        $rows = [];

        foreach ($stocks as $stock) {
            $batch = $stock->batch;

            if ($batch === null || ! $batch->isDispensable() || $stock->available() <= 0) {
                continue;
            }

            $rows[] = ['batch' => $batch, 'available' => $stock->available()];
        }

        usort($rows, static function (array $a, array $b): int {
            $left = $a['batch']->expires_on;
            $right = $b['batch']->expires_on;

            // Un lot sans date de péremption passe après ceux qui en ont une :
            // on ne fait pas passer l'inconnu avant le connu.
            return match (true) {
                $left === null && $right === null => $a['batch']->id <=> $b['batch']->id,
                $left === null => 1,
                $right === null => -1,
                default => [$left->getTimestamp(), $a['batch']->id] <=> [$right->getTimestamp(), $b['batch']->id],
            };
        });

        return $rows;
    }

    /**
     * Comment servir une quantité : la répartition sur les lots, du plus
     * proche de la péremption au plus lointain.
     *
     * Le reliquat (`missing`) n'est pas une erreur : c'est une information.
     * C'est à l'appelant (la dispensation) de décider s'il sert
     * partiellement ou s'il refuse.
     *
     * @return array{lines: list<array{batch: Batch, quantity: int}>, missing: int}
     */
    public function plan(Product $product, Location $location, int $quantity): array
    {
        $lines = [];
        $left = max(0, $quantity);

        foreach ($this->batchesFor($product, $location) as $row) {
            if ($left <= 0) {
                break;
            }

            $take = min($left, $row['available']);
            $lines[] = ['batch' => $row['batch'], 'quantity' => $take];
            $left -= $take;
        }

        return ['lines' => $lines, 'missing' => $left];
    }

    /**
     * Le lot que le système propose d'office.
     */
    public function suggest(Product $product, Location $location): ?Batch
    {
        return $this->batchesFor($product, $location)[0]['batch'] ?? null;
    }
}
