<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Staff;

use Keneya\Pharmacie\Contracts\StaffDirectory;

/**
 * L'annuaire par défaut : aucun personnel déclaré.
 *
 * Tant que l'application hôte n'a pas lié le sien, l'écran « Utilisateurs »
 * n'affiche que les personnes déjà réglées dans la pharmacie, et dit
 * clairement que l'hôte ne déclare personne. Inventer une liste serait pire
 * qu'une liste vide.
 */
final class NoStaffDirectory implements StaffDirectory
{
    public function staff(): array
    {
        return [];
    }
}
