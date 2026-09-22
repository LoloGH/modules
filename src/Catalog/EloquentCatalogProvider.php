<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Catalog;

use Illuminate\Database\Eloquent\Builder;
use Keneya\FinanceCaisse\Contracts\CatalogProvider;
use Keneya\FinanceCaisse\Models\Act;
use Keneya\FinanceCaisse\Models\Tariff;

/**
 * Le catalogue lu dans `finance_acts` et `finance_tariffs`.
 *
 * Le tarif d'un acte est sa ligne active pour le contexte demandé : `SetTariff`
 * garantit qu'il n'y en a qu'une. Les modèles ne sortent jamais d'ici : ils
 * sont convertis en {@see CatalogAct}.
 */
final class EloquentCatalogProvider implements CatalogProvider
{
    /**
     * @return list<CatalogAct>
     */
    public function actsForService(?int $hostServiceId): array
    {
        return $this->activeActs()
            ->where(static function (Builder $query) use ($hostServiceId): void {
                $query->whereNull('dme_service_id');

                if ($hostServiceId !== null) {
                    $query->orWhere('dme_service_id', $hostServiceId);
                }
            })
            // Les actes du service d'abord, les génériques ensuite.
            ->orderByRaw('CASE WHEN dme_service_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (Act $act): CatalogAct => $this->toDto($act))
            ->values()
            ->all();
    }

    public function findAct(int $actId): ?CatalogAct
    {
        $act = $this->activeActs()->whereKey($actId)->first();

        return $act === null ? null : $this->toDto($act);
    }

    public function activeTariffFor(int $actId, string $kind = Tariff::KIND_STANDARD): ?int
    {
        $amount = Tariff::query()
            ->where('act_id', $actId)
            ->where('kind', $kind)
            ->where('is_active', true)
            ->whereHas('act', static fn (Builder $query) => $query->where('is_active', true))
            ->value('amount');

        return $amount === null ? null : (int) $amount;
    }

    public function ticketAct(): ?CatalogAct
    {
        $act = $this->activeActs()->where('is_consultation_ticket', true)->orderBy('id')->first();

        return $act === null ? null : $this->toDto($act);
    }

    /**
     * @return Builder<Act>
     */
    private function activeActs(): Builder
    {
        return Act::query()->where('is_active', true)->with('standardTariff');
    }

    private function toDto(Act $act): CatalogAct
    {
        $tariff = $act->standardTariff;

        return new CatalogAct(
            id: (int) $act->id,
            code: (string) $act->code,
            name: (string) $act->name,
            hostServiceId: $act->dme_service_id === null ? null : (int) $act->dme_service_id,
            analyticCenterId: $act->analytic_center_id === null ? null : (int) $act->analytic_center_id,
            activeAmount: $tariff === null ? null : (int) $tariff->amount,
        );
    }
}
