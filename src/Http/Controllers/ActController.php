<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Keneya\FinanceCaisse\Actions\SetConsultationTicket;
use Keneya\FinanceCaisse\Audit\Auditor;
use Keneya\FinanceCaisse\Exceptions\FinanceRuleViolation;
use Keneya\FinanceCaisse\Http\Requests\ActCenterRequest;
use Keneya\FinanceCaisse\Http\Requests\ActRequest;
use Keneya\FinanceCaisse\Http\Requests\ConsultationTicketRequest;
use Keneya\FinanceCaisse\Models\Act;
use Keneya\FinanceCaisse\Models\AnalyticCenter;
use Keneya\FinanceCaisse\Models\Insurer;
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
            // Un acte porte un produit : les centres de charges ne s'y prêtent pas.
            'centers' => AnalyticCenter::query()->forRevenue()->get(),
        ]);
    }

    public function show(Act $act): View
    {
        $act->load('center');

        return view('finance::catalog.act', [
            'act' => $act,
            'centers' => AnalyticCenter::query()->forRevenue()->get(),
            // Les tarifs actifs d'abord, puis l'historique du plus récent au
            // plus ancien : le prix du jour se lit sans chercher.
            'tariffs' => $act->tariffs()->orderByDesc('is_active')->orderByDesc('id')->get(),
            'defaultKind' => Tariff::KIND_STANDARD,
            // Qui prend cet acte en charge, et à quel taux.
            'coverages' => Insurer::query()->active()->with('acts')->get()
                ->map(fn (Insurer $insurer): array => ['insurer' => $insurer, 'rate' => $insurer->rateFor($act->id)])
                ->filter(fn (array $row): bool => $row['rate'] > 0)
                ->values(),
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

    /**
     * Rattacher l'acte à un centre analytique, ou l'en détacher.
     *
     * Ce qui a déjà été encaissé ou facturé ne bouge pas : chaque écriture
     * porte le centre qu'elle avait au moment où elle a été écrite.
     */
    public function center(ActCenterRequest $request, Act $act, Auditor $auditor): RedirectResponse
    {
        $centerId = $request->validated('analytic_center_id');
        $center = $centerId === null ? null : AnalyticCenter::query()->findOrFail((int) $centerId);

        if ($center !== null && (! $center->is_active || ! $center->acceptsRevenue())) {
            throw new FinanceRuleViolation(
                "Le centre « {$center->name} » ne porte pas de produits : un acte ne peut pas s'y rattacher."
            );
        }

        $before = $act->center?->code;
        $act->update(['analytic_center_id' => $center?->id]);

        $auditor->record(
            'act_center_set',
            $act,
            sprintf('Acte « %s » rattaché à %s', $act->name, $center?->name ?? 'aucun centre analytique'),
            ['analytic_center' => $before],
            ['analytic_center' => $center?->code],
            $this->user($request),
        );

        return redirect()->route('finance.catalog.acts.show', $act)->with(
            'finance_status',
            $center === null
                ? "L'acte « {$act->name} » n'est plus rattaché à un centre analytique."
                : "L'acte « {$act->name} » est rattaché à « {$center->name} ». Les écritures passées gardent leur centre.",
        );
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

    /**
     * La case « ticket de consultation » : l'unicité (un seul acte à la fois)
     * est tenue par l'action.
     */
    public function ticket(ConsultationTicketRequest $request, Act $act, SetConsultationTicket $action): RedirectResponse
    {
        $act = $action->handle($act, $request->boolean('is_consultation_ticket'), $this->user($request));

        return redirect()->route('finance.catalog.acts.show', $act)->with(
            'finance_status',
            $act->is_consultation_ticket
                ? "L'acte « {$act->name} » est le ticket de consultation."
                : "L'acte « {$act->name} » n'est pas le ticket de consultation.",
        );
    }
}
