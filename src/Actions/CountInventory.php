<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Keneya\Pharmacie\Audit\Auditor;
use Keneya\Pharmacie\Exceptions\PharmacieRuleViolation;
use Keneya\Pharmacie\Models\Category;
use Keneya\Pharmacie\Models\Inventory;
use Keneya\Pharmacie\Models\InventoryLine;
use Keneya\Pharmacie\Models\Location;
use Keneya\Pharmacie\Models\Product;
use Keneya\Pharmacie\Models\Stock;
use Keneya\Pharmacie\Models\StockMovement;
use Keneya\Pharmacie\Services\NumberGenerator;
use Keneya\Pharmacie\Services\StockLedger;
use Keneya\Pharmacie\Support\Actor;
use Keneya\Pharmacie\Support\Facility;
use Keneya\Pharmacie\Support\Text;

/**
 * L'inventaire : compter ce qu'il y a, et regarder l'écart en face.
 *
 * Trois moments, trois responsabilités :
 *
 *   1. **ouvrir**, le théorique est figé, lot par lot : ce que le système
 *      croyait avoir au moment où l'on commence ;
 *   2. **compter**, on saisit le réel ; tant que l'inventaire est ouvert,
 *      rien n'est corrigé ;
 *   3. **valider**, quelqu'un d'AUTRE que celui qui a compté accepte les
 *      écarts, et c'est alors seulement que le stock est corrigé, par des
 *      écritures d'ajustement qui restent au grand livre.
 *
 * Un écart non justifié bloque la validation : c'est tout l'intérêt de
 * l'exercice.
 */
final class CountInventory
{
    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly StockLedger $ledger,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  array{category_id?: ?int, product_id?: ?int, notes?: ?string}  $details
     */
    public function open(Location $location, string $scope, Authenticatable $actor, array $details = []): Inventory
    {
        if (! array_key_exists($scope, Inventory::scopeLabels())) {
            throw new PharmacieRuleViolation('Portée d\'inventaire inconnue.');
        }

        return DB::transaction(function () use ($location, $scope, $actor, $details): Inventory {
            $category = isset($details['category_id']) ? Category::query()->find($details['category_id']) : null;
            $product = isset($details['product_id']) ? Product::query()->find($details['product_id']) : null;

            if ($scope === Inventory::SCOPE_CATEGORY && $category === null) {
                throw new PharmacieRuleViolation('Choisissez la catégorie à inventorier.');
            }

            if ($scope === Inventory::SCOPE_PRODUCT && $product === null) {
                throw new PharmacieRuleViolation('Choisissez le produit à inventorier.');
            }

            // Un seul inventaire ouvert par emplacement : deux comptages
            // simultanés au même endroit donneraient deux vérités.
            $open = Inventory::query()->ofFacility()
                ->where('location_id', $location->id)
                ->where('status', Inventory::STATUS_OPEN)
                ->first();

            if ($open !== null) {
                throw new PharmacieRuleViolation(
                    "Un inventaire est déjà en cours à {$location->name} ({$open->number}) : terminez-le d'abord."
                );
            }

            $inventory = Inventory::create([
                'facility_id' => Facility::current(),
                'number' => $this->numbers->next('inventory'),
                'location_id' => $location->id,
                'scope' => $scope,
                'category_id' => $category?->id,
                'product_id' => $product?->id,
                'status' => Inventory::STATUS_OPEN,
                'notes' => Text::clean($details['notes'] ?? null),
                'counted_by_id' => Actor::id($actor),
                'counted_by_name' => Actor::name($actor),
            ]);

            $stocks = Stock::query()
                ->where('location_id', $location->id)
                ->when($product, fn ($query) => $query->where('product_id', $product->id))
                ->when($category, fn ($query) => $query->whereHas('product', fn ($p) => $p->where('category_id', $category->id)))
                ->with(['product', 'batch'])
                ->get();

            foreach ($stocks as $stock) {
                InventoryLine::create([
                    'inventory_id' => $inventory->id,
                    'product_id' => $stock->product_id,
                    'batch_id' => $stock->batch_id,
                    'label' => $stock->product?->label() ?? '',
                    'expected_quantity' => (int) $stock->quantity,
                ]);
            }

            $this->auditor->record(
                'inventory_opened',
                $inventory,
                sprintf('Inventaire %s ouvert à %s : %d ligne(s) à compter', $inventory->number, $location->name, $stocks->count()),
                [],
                ['location' => $location->code, 'scope' => $scope, 'lines' => $stocks->count()],
                $actor,
            );

            return $inventory->refresh();
        });
    }

    /**
     * Saisir le comptage. Rien n'est corrigé ici : on note ce qu'on a vu.
     *
     * @param  array<int, array{counted: ?int, reason?: ?string}>  $counts  par identifiant de ligne
     */
    public function count(Inventory $inventory, array $counts, Authenticatable $actor): Inventory
    {
        return DB::transaction(function () use ($inventory, $counts): Inventory {
            $fresh = Inventory::query()->whereKey($inventory->getKey())->lockForUpdate()->firstOrFail();

            if (! $fresh->isOpen()) {
                throw new PharmacieRuleViolation("L'inventaire {$fresh->number} n'est plus ouvert : {$fresh->statusLabel()}.");
            }

            foreach ($fresh->lines as $line) {
                if (! array_key_exists($line->id, $counts)) {
                    continue;
                }

                $counted = $counts[$line->id]['counted'] ?? null;

                if ($counted === null || $counted === '') {
                    continue;
                }

                $counted = max(0, (int) $counted);

                $line->update([
                    'counted_quantity' => $counted,
                    'gap' => $counted - (int) $line->expected_quantity,
                    'gap_reason' => Text::clean($counts[$line->id]['reason'] ?? null),
                ]);
            }

            return $fresh->refresh();
        });
    }

    /**
     * Valider : les écarts deviennent des ajustements de stock.
     *
     * Celui qui a compté ne valide pas son propre comptage, et un écart sans
     * motif bloque tout, sinon l'inventaire ne sert qu'à effacer les
     * erreurs au lieu de les comprendre.
     */
    public function validate(Inventory $inventory, Authenticatable $actor): Inventory
    {
        return DB::transaction(function () use ($inventory, $actor): Inventory {
            $fresh = Inventory::query()->whereKey($inventory->getKey())->lockForUpdate()->firstOrFail();

            if (! $fresh->isOpen()) {
                throw new PharmacieRuleViolation("L'inventaire {$fresh->number} est déjà tranché : {$fresh->statusLabel()}.");
            }

            if ((string) $fresh->counted_by_id === Actor::id($actor)) {
                throw new PharmacieRuleViolation(
                    'On ne valide pas son propre comptage : un inventaire se contrôle à deux.'
                );
            }

            $lines = $fresh->lines()->with('batch')->get();
            $notCounted = $lines->filter(fn (InventoryLine $line): bool => ! $line->isCounted());

            if ($notCounted->isNotEmpty()) {
                throw new PharmacieRuleViolation(sprintf(
                    '%d ligne(s) n\'ont pas été comptées : terminez le comptage avant de valider.',
                    $notCounted->count(),
                ));
            }

            $unjustified = $lines->filter(
                fn (InventoryLine $line): bool => $line->gap !== 0 && Text::clean($line->gap_reason) === null,
            );

            if ($unjustified->isNotEmpty()) {
                throw new PharmacieRuleViolation(sprintf(
                    '%d écart(s) sans motif : un inventaire sert à comprendre les écarts, pas à les effacer.',
                    $unjustified->count(),
                ));
            }

            $location = $fresh->location;
            $corrected = 0;

            foreach ($lines as $line) {
                if ($line->gap === 0 || $line->batch === null) {
                    continue;
                }

                $reason = sprintf('Inventaire %s : %s', $fresh->number, $line->gap_reason);

                if ($line->gap > 0) {
                    $this->ledger->receive($line->batch, $location, (int) $line->gap, StockMovement::KIND_ADJUSTMENT, $actor, [
                        'document' => $fresh,
                        'document_number' => $fresh->number,
                        'reason' => $reason,
                    ]);
                } else {
                    $this->ledger->issue($line->batch, $location, abs((int) $line->gap), StockMovement::KIND_ADJUSTMENT, $actor, [
                        'document' => $fresh,
                        'document_number' => $fresh->number,
                        'reason' => $reason,
                    ]);
                }

                $corrected++;
            }

            $fresh->update([
                'status' => Inventory::STATUS_VALIDATED,
                'validated_at' => now(),
                'validated_by_id' => Actor::id($actor),
                'validated_by_name' => Actor::name($actor),
            ]);

            $this->auditor->record(
                'inventory_validated',
                $fresh,
                sprintf(
                    'Inventaire %s validé : %d écart(s) corrigé(s) sur %d ligne(s)',
                    $fresh->number,
                    $corrected,
                    $lines->count(),
                ),
                ['status' => Inventory::STATUS_OPEN],
                ['status' => Inventory::STATUS_VALIDATED, 'corrections' => $corrected],
                $actor,
            );

            return $fresh->refresh();
        });
    }

    public function cancel(Inventory $inventory, string $reason, Authenticatable $actor): Inventory
    {
        $reason = Text::clean($reason);

        if ($reason === null) {
            throw new PharmacieRuleViolation('Abandonner un inventaire demande un motif.');
        }

        return DB::transaction(function () use ($inventory, $reason, $actor): Inventory {
            $fresh = Inventory::query()->whereKey($inventory->getKey())->lockForUpdate()->firstOrFail();

            if (! $fresh->isOpen()) {
                throw new PharmacieRuleViolation("L'inventaire {$fresh->number} est déjà tranché : {$fresh->statusLabel()}.");
            }

            $fresh->update([
                'status' => Inventory::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            $this->auditor->record(
                'inventory_cancelled',
                $fresh,
                sprintf('Inventaire %s abandonné : %s', $fresh->number, $reason),
                ['status' => Inventory::STATUS_OPEN],
                ['status' => Inventory::STATUS_CANCELLED, 'reason' => $reason],
                $actor,
            );

            return $fresh;
        });
    }
}
