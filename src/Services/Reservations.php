<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Services;

use Keneya\Pharmacie\Models\Dispensation;
use Keneya\Pharmacie\Models\DispensationReservation;
use Keneya\Pharmacie\Models\Location;

/**
 * Mettre de côté, et rendre.
 *
 * Réserver ne fait sortir aucune unité : elles restent sur l'étagère, elles
 * quittent seulement le disponible. C'est la différence entre « promis à
 * quelqu'un » et « parti », et le grand livre ne doit connaître que la
 * seconde.
 *
 * Toute réservation finit rendue, d'une façon ou d'une autre : à la
 * délivrance, parce que les unités sortent pour de bon, ou à l'abandon,
 * parce qu'elles redeviennent servables. Une réservation oubliée immobilise
 * du stock sans que rien ne le dise, et c'est pour cela que les deux chemins
 * passent ici.
 */
final class Reservations
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly StockPicker $picker,
    ) {}

    /**
     * Met de côté ce que la préparation demande, lot par lot, dans l'ordre
     * des péremptions : si un lot est promis, autant que ce soit celui qui
     * aurait été servi.
     */
    public function hold(Dispensation $dispensation, Location $location): int
    {
        $held = 0;

        foreach ($dispensation->items()->with(['product', 'location'])->get() as $item) {
            $product = $item->product;

            if ($product === null) {
                continue;
            }

            // Une ligne prise ailleurs se réserve ailleurs : mettre de côté
            // au comptoir ce qui dort à la centrale ne mettrait rien de côté.
            $from = $item->servingLocation($location);

            if ($from === null) {
                continue;
            }

            foreach ($this->picker->plan($product, $from, (int) $item->prescribed_quantity)['lines'] as $row) {
                $this->ledger->reserve($row['batch'], $from, $row['quantity']);

                DispensationReservation::create([
                    'dispensation_id' => $dispensation->id,
                    'batch_id' => $row['batch']->id,
                    'location_id' => $from->id,
                    'quantity' => $row['quantity'],
                ]);

                $held += (int) $row['quantity'];
            }
        }

        return $held;
    }

    /**
     * Rend au disponible ce qui avait été mis de côté, et dit combien.
     */
    public function release(Dispensation $dispensation): int
    {
        $released = 0;

        foreach ($dispensation->reservations()->with(['batch', 'location'])->get() as $reservation) {
            if ($reservation->batch === null || $reservation->location === null) {
                continue;
            }

            $this->ledger->release($reservation->batch, $reservation->location, (int) $reservation->quantity);
            $released += (int) $reservation->quantity;

            $reservation->delete();
        }

        return $released;
    }
}
