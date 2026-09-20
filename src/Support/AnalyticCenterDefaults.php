<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Support;

use Keneya\FinanceCaisse\Models\AnalyticCenter;

/**
 * Centres analytiques créés par `finance:sync-catalog`. Ils reprennent les
 * services de l'hôpital et ne sont qu'un point de départ : l'établissement
 * les renomme, les désactive, en ajoute, et surtout saisit lui-même ses
 * actes et ses tarifs (aucun prix n'est codé en dur ici).
 */
final class AnalyticCenterDefaults
{
    /**
     * @return list<array{code: string, name: string, kind: string}>
     */
    public static function all(): array
    {
        return [
            ['code' => 'CONSULTATION', 'name' => 'Consultation', 'kind' => AnalyticCenter::KIND_REVENUE],
            ['code' => 'URGENCES', 'name' => 'Urgences', 'kind' => AnalyticCenter::KIND_REVENUE],
            ['code' => 'LABORATOIRE', 'name' => 'Laboratoire', 'kind' => AnalyticCenter::KIND_REVENUE],
            ['code' => 'IMAGERIE', 'name' => 'Imagerie', 'kind' => AnalyticCenter::KIND_REVENUE],
            ['code' => 'HOSPITALISATION', 'name' => 'Hospitalisation', 'kind' => AnalyticCenter::KIND_REVENUE],
            ['code' => 'MATERNITE', 'name' => 'Maternité', 'kind' => AnalyticCenter::KIND_REVENUE],
            ['code' => 'PHARMACIE', 'name' => 'Pharmacie', 'kind' => AnalyticCenter::KIND_BOTH],
        ];
    }
}
