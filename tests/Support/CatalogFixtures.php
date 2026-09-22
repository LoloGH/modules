<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Support;

use Keneya\FinanceCaisse\Actions\SetTariff;
use Keneya\FinanceCaisse\Models\Act;
use Keneya\FinanceCaisse\Models\AnalyticCenter;
use Keneya\FinanceCaisse\Models\Tariff;

/**
 * Données de test communes au catalogue des actes et à leurs tarifs.
 */
trait CatalogFixtures
{
    protected function makeCenter(string $code = 'LABORATOIRE', ?AnalyticCenter $parent = null, bool $active = true): AnalyticCenter
    {
        return AnalyticCenter::firstOrCreate(
            ['code' => $code],
            [
                'name' => ucfirst(strtolower($code)),
                'parent_id' => $parent?->id,
                'kind' => AnalyticCenter::KIND_REVENUE,
                'is_active' => $active,
            ],
        );
    }

    protected function makeAct(string $code = 'CONS-GEN', ?AnalyticCenter $center = null, bool $active = true, ?int $hostServiceId = null): Act
    {
        return Act::firstOrCreate(
            ['code' => $code],
            [
                'name' => "Acte {$code}",
                'analytic_center_id' => $center?->id,
                'dme_service_id' => $hostServiceId,
                'is_active' => $active,
            ],
        );
    }

    protected function setTariff(Act $act, int $amount, string $kind = Tariff::KIND_STANDARD, ?string $label = null, ?TestUser $actor = null): Tariff
    {
        return app(SetTariff::class)->handle($act, $amount, $kind, $label, null, $actor);
    }
}
