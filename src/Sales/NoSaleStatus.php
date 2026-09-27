<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Sales;

use Keneya\Pharmacie\Contracts\SaleStatusProvider;

/**
 * Le lecteur par défaut : aucune caisse branchée, donc aucun état connu.
 *
 * Rendre « impayé » serait mentir, et rendre « payé » serait pire : on
 * délivrerait sans que personne ait rien encaissé. Ne rien savoir est la
 * seule réponse honnête, et l'écran la traduit en « la caisse n'est pas
 * branchée ».
 */
final class NoSaleStatus implements SaleStatusProvider
{
    public function status(string $reference): ?SaleStatus
    {
        return null;
    }
}
