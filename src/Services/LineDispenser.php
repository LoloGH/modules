<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Keneya\Pharmacie\Audit\Auditor;
use Keneya\Pharmacie\Exceptions\PharmacieRuleViolation;
use Keneya\Pharmacie\Models\Batch;
use Keneya\Pharmacie\Models\Dispensation;
use Keneya\Pharmacie\Models\DispensationBatch;
use Keneya\Pharmacie\Models\DispensationItem;
use Keneya\Pharmacie\Models\Location;
use Keneya\Pharmacie\Models\Product;
use Keneya\Pharmacie\Models\StockMovement;
use Keneya\Pharmacie\Support\Controlled;
use Keneya\Pharmacie\Support\Text;

/**
 * Servir une ligne : choisir les lots, sortir le stock, écrire le détail.
 *
 * Ce geste existe à deux moments du module, et il doit être le même aux deux :
 * la vente immédiate au comptoir, et la délivrance d'une préparation déjà
 * payée. Deux implémentations, ce serait deux FEFO, deux façons de tracer une
 * dérogation, et un jour deux réponses à la même question.
 *
 * Ce qu'il garantit, où qu'il soit appelé :
 *
 *   - **FEFO d'office** : sans lot désigné, ce qui périme en premier sort en
 *     premier, réparti sur plusieurs lots si nécessaire ;
 *   - **une dérogation se justifie** : servir un autre lot que celui proposé
 *     est permis, jamais en silence ;
 *   - **le lot se choisit au moment de la sortie**, pas la veille : entre la
 *     préparation et la délivrance, le stock a pu bouger, et c'est l'état du
 *     jour qui fait foi ;
 *   - **ce qui manque ne s'invente pas** : on sert ce qui est disponible, et
 *     le reliquat reste visible.
 */
final class LineDispenser
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly StockPicker $picker,
        private readonly Auditor $auditor,
    ) {}

    /**
     * Sert une ligne déjà écrite, et rend ce qui est réellement sorti.
     *
     * @param  array{batch_id?: ?int, override_reason?: ?string}  $options
     */
    public function serve(
        Dispensation $dispensation,
        DispensationItem $item,
        Location $location,
        int $wanted,
        array $options,
        Authenticatable $dispenser,
        int $position,
    ): int {
        $product = $item->product;

        if ($product === null) {
            throw new PharmacieRuleViolation("Ligne {$position} : produit introuvable.");
        }

        $served = 0;

        foreach ($this->planFor($product, $location, $wanted, $options, $position) as $row) {
            $this->ledger->issue(
                $row['batch'],
                $location,
                $row['quantity'],
                StockMovement::KIND_DISPENSING,
                $dispenser,
                ['document' => $dispensation, 'document_number' => $dispensation->number],
            );

            DispensationBatch::create([
                'dispensation_item_id' => $item->id,
                'batch_id' => $row['batch']->id,
                'quantity' => $row['quantity'],
                'overrode_fefo' => $row['overrode_fefo'],
                'override_reason' => $row['override_reason'],
            ]);

            $served += $row['quantity'];
        }

        $item->update(['quantity' => $served, 'amount' => $served * (int) $item->unit_price]);

        $this->traceControlled($dispensation, $product, $served, $dispenser);

        return $served;
    }

    /**
     * Le plan de sortie : FEFO par défaut, ou le lot désigné, et alors la
     * dérogation est tracée.
     *
     * @param  array{batch_id?: ?int, override_reason?: ?string}  $options
     * @return list<array{batch: Batch, quantity: int, overrode_fefo: bool, override_reason: ?string}>
     */
    private function planFor(Product $product, Location $location, int $wanted, array $options, int $position): array
    {
        if ($wanted <= 0) {
            return [];
        }

        if (! isset($options['batch_id']) || $options['batch_id'] === null) {
            return array_map(
                static fn (array $row): array => [
                    'batch' => $row['batch'],
                    'quantity' => $row['quantity'],
                    'overrode_fefo' => false,
                    'override_reason' => null,
                ],
                $this->picker->plan($product, $location, $wanted)['lines'],
            );
        }

        $batch = Batch::query()->find((int) $options['batch_id']);

        if ($batch === null || (int) $batch->product_id !== (int) $product->id) {
            throw new PharmacieRuleViolation("Ligne {$position} ({$product->name}) : ce lot n'appartient pas à ce produit.");
        }

        $suggested = $this->picker->suggest($product, $location);
        $overrode = $suggested !== null && (int) $suggested->id !== (int) $batch->id;
        $reason = Text::clean($options['override_reason'] ?? null);

        // Servir un autre lot que celui proposé est permis, mais jamais en
        // silence : c'est la règle qui protège le FEFO d'être contourné par
        // habitude.
        if ($overrode && $reason === null) {
            throw new PharmacieRuleViolation(sprintf(
                'Ligne %d (%s) : le lot %s périme avant le lot %s. Pour servir celui-ci, indiquez un motif.',
                $position,
                $product->name,
                $suggested->number,
                $batch->number,
            ));
        }

        $available = $this->ledger->available($batch, $location);

        return [[
            'batch' => $batch,
            'quantity' => min($wanted, $available),
            'overrode_fefo' => $overrode,
            'override_reason' => $overrode ? $reason : null,
        ]];
    }

    /**
     * Un produit sous surveillance laisse une trace nominative, en plus du
     * mouvement de stock : c'est ce qui rend le registre opposable.
     */
    private function traceControlled(Dispensation $dispensation, Product $product, int $served, Authenticatable $dispenser): void
    {
        if ($served <= 0 || ! Controlled::applies($product)) {
            return;
        }

        $this->auditor->record(
            'controlled_dispensed',
            $dispensation,
            sprintf(
                'Produit sous surveillance délivré : %d %s de %s à %s (%s)',
                $served,
                $product->unit ?? 'unité',
                $product->label(),
                $dispensation->patient_name ?? $dispensation->patient_id ?? 'patient non désigné',
                $dispensation->prescription_ref ?? 'sans ordonnance',
            ),
            [],
            [
                'product' => $product->code,
                'quantity' => $served,
                'patient_id' => $dispensation->patient_id,
                'prescription' => $dispensation->prescription_ref,
            ],
            $dispenser,
        );
    }
}
