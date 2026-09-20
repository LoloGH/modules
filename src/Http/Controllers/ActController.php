<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Keneya\FinanceCaisse\Audit\Auditor;
use Keneya\FinanceCaisse\Http\Requests\ActRequest;
use Keneya\FinanceCaisse\Models\Act;
use Keneya\FinanceCaisse\Models\AnalyticCenter;
use Keneya\FinanceCaisse\Models\Tariff;
use Keneya\FinanceCaisse\Support\Text;

/**
 * Actes et prestations facturables. Un acte se désactive, il ne se supprime
 * pas : une facture passée s'y réfère.
 */
final class ActController extends FinanceController
{
    public function index(): View
    {
        return view('finance::catalog.acts', [
            'acts' => Act::query()->with(['center', 'standardTariff'])->orderBy('name')->get(),
            'centers' => AnalyticCenter::query()->active()->get(),
        ]);
    }

    public function show(Act $act): View
    {
        $act->load('center');

        return view('finance::catalog.act', [
            'act' => $act,
            // Les tarifs actifs d'abord, puis l'historique du plus récent au
            // plus ancien : le prix du jour se lit sans chercher.
            'tariffs' => $act->tariffs()->orderByDesc('is_active')->orderByDesc('id')->get(),
            'defaultKind' => Tariff::KIND_STANDARD,
        ]);
    }

    public function store(ActRequest $request, Auditor $auditor): RedirectResponse
    {
        $centerId = $request->validated('analytic_center_id');
        $serviceId = $request->validated('dme_service_id');

        $act = Act::create([
            'code' => strtoupper((string) Text::clean($request->validated('code'))),
            'name' => (string) Text::clean($request->validated('name')),
            'analytic_center_id' => $centerId === null ? null : (int) $centerId,
            'dme_service_id' => $serviceId === null ? null : (int) $serviceId,
            'description' => Text::clean($request->validated('description')),
            'is_active' => true,
        ]);

        $auditor->record(
            'act_created',
            $act,
            "Acte {$act->name} ({$act->code}) créé",
            [],
            ['code' => $act->code, 'name' => $act->name, 'analytic_center_id' => $act->analytic_center_id],
            $this->user($request),
        );

        return redirect()->route('finance.catalog.acts.show', $act)
            ->with('finance_status', "L'acte « {$act->name} » a été créé. Fixez son tarif.");
    }

    public function toggle(Request $request, Act $act, Auditor $auditor): RedirectResponse
    {
        $user = $this->user($request);

        $message = DB::transaction(function () use ($act, $user, $auditor): string {
            $act = Act::query()->whereKey($act->getKey())->lockForUpdate()->firstOrFail();

            $active = ! $act->is_active;
            $act->update(['is_active' => $active]);

            $auditor->record(
                $active ? 'act_activated' : 'act_deactivated',
                $act,
                sprintf('Acte « %s » %s', $act->name, $active ? 'activé' : 'désactivé'),
                ['is_active' => ! $active],
                ['is_active' => $active],
                $user,
            );

            return sprintf('L\'acte « %s » est %s.', $act->name, $active ? 'activé' : 'désactivé');
        });

        return redirect()->route('finance.catalog.acts.index')->with('finance_status', $message);
    }
}
