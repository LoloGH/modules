<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Support;

/**
 * L'établissement (ou la pharmacie) dans lequel on travaille.
 *
 * Toutes les tables du module portent `facility_id` dès la première migration.
 * Tant qu'un seul établissement existe, cette classe rend toujours la même
 * valeur et personne ne s'en aperçoit ; le jour où une pharmacie secondaire
 * ouvre, il suffit à l'hôte de déclarer laquelle est regardée, aucune table
 * n'est à réécrire.
 */
final class Facility
{
    private static ?int $current = null;

    /**
     * L'hôte désigne l'établissement regardé (par exemple depuis la session
     * de l'utilisateur).
     */
    public static function use(?int $facilityId): void
    {
        self::$current = $facilityId;
    }

    public static function current(): int
    {
        return self::$current
            ?? (int) config('pharmacie.facility.id', 1);
    }

    public static function flushState(): void
    {
        self::$current = null;
    }
}
