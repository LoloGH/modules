<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Sales;

use Keneya\Pharmacie\Contracts\SaleSink;

/**
 * Le point de sortie par défaut : aucun.
 *
 * Tant que l'hôte n'a pas branché la caisse, une dispensation reste dans la
 * pharmacie : elle est tracée, elle sort du stock, mais elle n'est envoyée
 * nulle part. Le module reste ainsi utilisable seul, et rien n'est encaissé
 * en douce.
 */
final class NoSaleSink implements SaleSink
{
    public function send(DispensedSale $sale): ?string
    {
        return null;
    }
}
