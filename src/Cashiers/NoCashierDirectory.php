<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Cashiers;

use Keneya\FinanceCaisse\Contracts\CashierDirectory;

/**
 * Sans hôte, aucun annuaire : un caissier n'est connu qu'après sa première
 * session, comme avant.
 */
final class NoCashierDirectory implements CashierDirectory
{
    public function cashiers(): array
    {
        return [];
    }
}
