<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Keneya\Pharmacie\Exceptions\PharmacieRuleViolation;
use Keneya\Pharmacie\Models\Batch;
use Keneya\Pharmacie\Models\Location;
use Keneya\Pharmacie\Models\Stock;
use Keneya\Pharmacie\Models\StockMovement;
use Keneya\Pharmacie\Support\Actor;
use Keneya\Pharmacie\Support\Text;

/**
 * Le grand livre du stock : le seul endroit par où une quantité bouge.
 *
 * Toute entrée, sortie, correction ou perte passe ici, et y laisse une
 * écriture immuable. Les quantités de `pharmacie_stocks` en découlent : elles
 * sont mises à jour **sous verrou**, dans la même transaction que l'écriture,
 * et peuvent être recalculées à tout moment ({@see recompute()}).
 *
 * Trois refus, toujours les mêmes, et jamais contournables :
 *
 *   - on ne sort pas plus que ce qui est disponible ;
 *   - on ne sort pas d'un lot périmé, bloqué ou détruit ;
 *   - on ne bouge rien sans motif lorsque le motif est ce qui justifie le
 *     mouvement (ajustement, perte, destruction).
 */
final class StockLedger
{
    /**
     * Fait entrer des unités dans un lot, à un emplacement.
     *
     * @param  array{reason?: ?string, document?: ?Model, document_number?: ?string}  $context
     */
    public function receive(Batch $batch, Location $location, int $quantity, string $kind, Authenticatable $actor, array $context = []): StockMovement
    {
        if ($quantity <= 0) {
            throw new PharmacieRuleViolation('La quantité reçue doit être supérieure à zéro.');
        }

        return $this->write($batch, $location, $quantity, $kind, $actor, $context);
    }

    /**
     * Fait sortir des unités. `$quantity` est positive : c'est le ledger qui
     * sait que c'est une sortie.
     *
     * @param  array{reason?: ?string, document?: ?Model, document_number?: ?string, allow_expired?: bool}  $context
     */
    public function issue(Batch $batch, Location $location, int $quantity, string $kind, Authenticatable $actor, array $context = []): StockMovement
    {
        if ($quantity <= 0) {
            throw new PharmacieRuleViolation('La quantité sortie doit être supérieure à zéro.');
        }

        return $this->write($batch, $location, -$quantity, $kind, $actor, $context);
    }

    /**
     * Réserve des unités : elles restent physiquement là, mais ne sont plus
     * disponibles pour quelqu'un d'autre. Aucune écriture au grand livre —
     * rien n'est sorti.
     */
    public function reserve(Batch $batch, Location $location, int $quantity): void
    {
        $this->moveReservation($batch, $location, $quantity);
    }

    public function release(Batch $batch, Location $location, int $quantity): void
    {
        $this->moveReservation($batch, $location, -$quantity);
    }

    /**
     * Ce qui est réellement servable de ce lot, à cet emplacement.
     */
    public function available(Batch $batch, Location $location): int
    {
        $stock = Stock::query()
            ->where('batch_id', $batch->getKey())
            ->where('location_id', $location->getKey())
            ->first();

        return $stock?->available() ?? 0;
    }

    /**
     * Recalcule une ligne de stock à partir des mouvements. C'est le filet :
     * si la commodité de lecture et le grand livre divergent, c'est le grand
     * livre qui a raison.
     */
    public function recompute(Batch $batch, Location $location): int
    {
        return DB::transaction(function () use ($batch, $location): int {
            $quantity = (int) StockMovement::query()
                ->where('batch_id', $batch->getKey())
                ->where('location_id', $location->getKey())
                ->sum('quantity');

            $stock = $this->lockStock($batch, $location);
            $stock->update(['quantity' => max(0, $quantity)]);

            return (int) $stock->quantity;
        });
    }

    /**
     * @param  array{reason?: ?string, document?: ?Model, document_number?: ?string, allow_expired?: bool}  $context
     */
    private function write(Batch $batch, Location $location, int $signed, string $kind, Authenticatable $actor, array $context): StockMovement
    {
        $reason = Text::clean($context['reason'] ?? null);

        if ($signed < 0 && in_array($kind, [StockMovement::KIND_ADJUSTMENT, StockMovement::KIND_LOSS, StockMovement::KIND_DESTRUCTION], true) && $reason === null) {
            throw new PharmacieRuleViolation('Un ajustement, une perte ou une destruction doit avoir un motif.');
        }

        return DB::transaction(function () use ($batch, $location, $signed, $kind, $actor, $context, $reason): StockMovement {
            $batch = Batch::query()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();
            $stock = $this->lockStock($batch, $location);

            if ($signed < 0) {
                $this->assertCanIssue($batch, $stock, abs($signed), $kind, $context);
            }

            $after = (int) $stock->quantity + $signed;

            $stock->update(['quantity' => max(0, $after)]);

            $document = $context['document'] ?? null;

            return StockMovement::create([
                'facility_id' => $batch->facility_id,
                'product_id' => $batch->product_id,
                'batch_id' => $batch->id,
                'location_id' => $location->id,
                'kind' => $kind,
                'quantity' => $signed,
                'quantity_after' => max(0, $after),
                'reason' => $reason,
                'document_type' => $document?->getMorphClass(),
                'document_id' => $document?->getKey(),
                'document_number' => Text::clean($context['document_number'] ?? null),
                'actor_id' => Actor::id($actor),
                'actor_name' => Actor::name($actor),
            ]);
        });
    }

    /**
     * @param  array{allow_expired?: bool}  $context
     */
    private function assertCanIssue(Batch $batch, Stock $stock, int $quantity, string $kind, array $context): void
    {
        // Sortir un lot périmé pour le détruire ou le perdre : c'est
        // précisément ce qu'il faut pouvoir faire. Le servir : jamais.
        $destructive = in_array($kind, [StockMovement::KIND_DESTRUCTION, StockMovement::KIND_LOSS, StockMovement::KIND_ADJUSTMENT], true);

        if (! $destructive && ($context['allow_expired'] ?? false) !== true) {
            if ($batch->isExpired()) {
                throw new PharmacieRuleViolation(
                    "Le lot {$batch->number} est périmé depuis le {$batch->expires_on?->format('d/m/Y')} : il ne peut pas être délivré."
                );
            }

            if ($batch->isBlocked()) {
                throw new PharmacieRuleViolation("Le lot {$batch->number} est bloqué : il ne peut pas être délivré.");
            }
        }

        $available = $destructive ? (int) $stock->quantity : $stock->available();

        if ($quantity > $available) {
            throw new PharmacieRuleViolation(sprintf(
                'Le lot %s ne contient que %d unité(s) disponible(s) : %d ne peuvent pas en sortir.',
                $batch->number,
                $available,
                $quantity,
            ));
        }
    }

    private function moveReservation(Batch $batch, Location $location, int $delta): void
    {
        DB::transaction(function () use ($batch, $location, $delta): void {
            $stock = $this->lockStock($batch, $location);
            $reserved = (int) $stock->reserved + $delta;

            if ($reserved > (int) $stock->quantity) {
                throw new PharmacieRuleViolation(
                    "Le lot {$batch->number} n'a pas assez d'unités pour être réservé à ce point."
                );
            }

            $stock->update(['reserved' => max(0, $reserved)]);
        });
    }

    private function lockStock(Batch $batch, Location $location): Stock
    {
        Stock::query()->firstOrCreate(
            ['batch_id' => $batch->getKey(), 'location_id' => $location->getKey()],
            [
                'facility_id' => $batch->facility_id,
                'product_id' => $batch->product_id,
                'quantity' => 0,
                'reserved' => 0,
            ],
        );

        return Stock::query()
            ->where('batch_id', $batch->getKey())
            ->where('location_id', $location->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }
}
