<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Coverage;

use Keneya\Pharmacie\Contracts\InsurerDirectory;

/**
 * Sans hote branche : aucun organisme.
 *
 * La pharmacie reste utilisable (on sert, on facture, le patient paie tout)
 * et l'ecran dit que les prises en charge se declarent dans la caisse, plutot
 * que d'offrir une liste vide sans explication.
 */
final class NoInsurers implements InsurerDirectory
{
    public function insurers(): array
    {
        return [];
    }
}
