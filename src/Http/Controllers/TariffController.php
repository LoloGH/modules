<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Keneya\FinanceCaisse\Actions\SetTariff;
use Keneya\FinanceCaisse\Http\Requests\TariffRequest;
use Keneya\FinanceCaisse\Models\Act;
use Keneya\FinanceCaisse\Support\Money;

/**
 * Fixer ou changer le tarif d'un acte. Rien ne se modifie sur place : la
 * règle et l'écriture vivent dans l'action `SetTariff`.
 */
final class TariffController extends FinanceController
{
    public function store(TariffRequest $request, Act $act, SetTariff $action): RedirectResponse
    {
        $tariff = $action->handle(
            $act,
            (int) $request->validated('amount'),
            (string) $request->validated('kind'),
            $request->validated('label'),
            $request->validated('effective_from'),
            $this->user($request),
        );

        return redirect()->route('finance.catalog.acts.show', $act)->with(
            'finance_status',
            sprintf('Tarif « %s » de %s fixé à %s.', $tariff->kind, $act->name, Money::format((int) $tariff->amount)),
        );
    }
}
