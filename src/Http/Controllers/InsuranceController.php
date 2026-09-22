<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Keneya\FinanceCaisse\Actions\RecordInsuranceRejection;
use Keneya\FinanceCaisse\Actions\RecordInsuranceSettlement;
use Keneya\FinanceCaisse\Actions\SetInsurerCoverage;
use Keneya\FinanceCaisse\Audit\Auditor;
use Keneya\FinanceCaisse\Http\Requests\InsuranceRejectionRequest;
use Keneya\FinanceCaisse\Http\Requests\InsuranceSettlementRequest;
use Keneya\FinanceCaisse\Http\Requests\InsurerCoverageRequest;
use Keneya\FinanceCaisse\Http\Requests\InsurerRequest;
use Keneya\FinanceCaisse\Models\Act;
use Keneya\FinanceCaisse\Models\InsuranceRejection;
use Keneya\FinanceCaisse\Models\InsuranceSettlement;
use Keneya\FinanceCaisse\Models\Insurer;
use Keneya\FinanceCaisse\Models\Invoice;
use Keneya\FinanceCaisse\Support\Money;
use Keneya\FinanceCaisse\Support\Text;

/**
 * Assurances : les prises en charge (factures d'assurance, parts, règlements,
 * rejets, créance restante) et le catalogue des assureurs.
 */
final class InsuranceController extends FinanceController
{
    private const PER_PAGE = 50;

    public function index(Request $request): View
    {
        $insurerId = ctype_digit((string) $request->query('assureur')) ? (int) $request->query('assureur') : null;
        $claim = array_key_exists((string) $request->query('statut'), Invoice::claimLabels()) ? (string) $request->query('statut') : null;
        $search = Text::clean(is_string($request->query('q')) ? $request->query('q') : null);
        $kind = array_key_exists((string) $request->query('nature'), Insurer::kindLabels()) ? (string) $request->query('nature') : null;

        $base = Invoice::query()->whereNotNull('insurer_id')
            ->when($kind, fn (Builder $q) => $q->whereHas('insurer', fn (Builder $i) => $i->where('kind', $kind)))
            ->where('status', '!=', Invoice::STATUS_CANCELLED)
            ->when($insurerId, fn (Builder $q) => $q->where('insurer_id', $insurerId))
            ->when($search, function (Builder $q) use ($search): void {
                $like = '%'.$search.'%';
                $q->where(fn (Builder $w) => $w->where('number', 'like', $like)
                    ->orWhere('patient_name', 'like', $like)
                    ->orWhere('patient_id', 'like', $like)
                    ->orWhere('policy_number', 'like', $like));
            });

        $stats = [
            'share' => (int) (clone $base)->sum('insurer_share'),
            'paid' => (int) (clone $base)->sum('insurer_paid'),
            'rejected' => (int) (clone $base)->sum('insurer_rejected'),
            // Assurances et aides sociales, chacune sa part.
            'byKind' => collect(Insurer::kindLabels())->map(fn (string $label, string $code): int => (int) (clone $base)
                ->whereHas('insurer', fn (Builder $i) => $i->where('kind', $code))->sum('insurer_share'))->all(),
        ];

        return view('finance::insurance.index', [
            'invoices' => (clone $base)->with('insurer')
                ->when($claim, fn (Builder $q) => $q->where('claim_status', $claim))
                ->latest('created_at')->latest('id')
                ->paginate(self::PER_PAGE)->withQueryString(),
            'stats' => $stats + ['outstanding' => max(0, $stats['share'] - $stats['paid'] - $stats['rejected'])],
            'insurers' => Insurer::query()->withCount('invoices')->orderBy('name')->get(),
            'insurerId' => $insurerId,
            'claim' => $claim,
            'kind' => $kind,
            'search' => $search,
            'settlements' => InsuranceSettlement::query()->with(['insurer', 'invoice'])->latest('id')->limit(10)->get(),
            'rejections' => InsuranceRejection::query()->with(['insurer', 'invoice'])->latest('id')->limit(10)->get(),
        ]);
    }

    public function storeInsurer(InsurerRequest $request, Auditor $auditor): RedirectResponse
    {
        $insurer = Insurer::create([
            'code' => strtoupper((string) Text::clean($request->validated('code'))),
            'name' => (string) Text::clean($request->validated('name')),
            'kind' => (string) $request->validated('kind'),
            'coverage_scope' => (string) $request->validated('coverage_scope'),
            'default_rate' => (int) $request->validated('default_rate'),
            'phone' => Text::clean($request->validated('phone')),
            'email' => Text::clean($request->validated('email')),
            'is_active' => true,
        ]);

        $auditor->record('insurer_created', $insurer, "Assureur {$insurer->name} ({$insurer->code}) créé, taux par défaut {$insurer->default_rate} %", [],
            ['code' => $insurer->code, 'default_rate' => $insurer->default_rate], $this->user($request));

        // « Actes choisis » : il reste à dire lesquels.
        return $insurer->coverage_scope === Insurer::SCOPE_SELECTED
            ? redirect()->route('finance.insurers.show', $insurer)->with('finance_status', "L'organisme « {$insurer->name} » a été créé. Cochez les actes qu'il couvre.")
            : redirect()->route('finance.insurance.index')->with('finance_status', "L'organisme « {$insurer->name} » a été créé.");
    }

    /**
     * La couverture d'un organisme, acte par acte.
     */
    public function showInsurer(Insurer $insurer): View
    {
        $insurer->load('acts');

        return view('finance::insurance.insurer', [
            'insurer' => $insurer,
            'acts' => Act::query()->active()->with(['center', 'standardTariff'])->get(),
            'rules' => $insurer->acts->mapWithKeys(fn (Act $act): array => [$act->id => $act->pivot->rate])->all(),
        ]);
    }

    public function updateCoverage(InsurerCoverageRequest $request, Insurer $insurer, SetInsurerCoverage $action): RedirectResponse
    {
        $action->handle(
            $insurer,
            (string) $request->validated('coverage_scope'),
            (int) $request->validated('default_rate'),
            $request->actRules(),
            $this->user($request),
        );

        return redirect()->route('finance.insurers.show', $insurer)->with('finance_status', "Couverture de « {$insurer->name} » enregistrée.");
    }

    public function toggleInsurer(Request $request, Insurer $insurer, Auditor $auditor): RedirectResponse
    {
        $active = ! $insurer->is_active;
        $insurer->update(['is_active' => $active]);

        $auditor->record($active ? 'insurer_activated' : 'insurer_deactivated', $insurer,
            sprintf('Assureur « %s » %s', $insurer->name, $active ? 'activé' : 'désactivé'),
            ['is_active' => ! $active], ['is_active' => $active], $this->user($request));

        return redirect()->route('finance.insurance.index')
            ->with('finance_status', sprintf("L'assureur « %s » est %s.", $insurer->name, $active ? 'activé' : 'désactivé'));
    }

    public function settle(InsuranceSettlementRequest $request, Invoice $invoice, RecordInsuranceSettlement $action): RedirectResponse
    {
        $settlement = $action->handle(
            $invoice,
            (int) $request->validated('amount'),
            $request->validated('reference'),
            $request->validated('received_on'),
            $this->user($request),
        );

        return redirect()->route('finance.invoices.show', $invoice)->with('finance_status', sprintf(
            'Règlement %s de %s enregistré. L\'assureur doit encore %s.',
            $settlement->number,
            Money::format($settlement->amount),
            Money::format($invoice->fresh()->insurerOutstanding()),
        ));
    }

    public function reject(InsuranceRejectionRequest $request, Invoice $invoice, RecordInsuranceRejection $action): RedirectResponse
    {
        $rejection = $action->handle($invoice, (int) $request->validated('amount'), (string) $request->validated('reason'), $this->user($request));

        return redirect()->route('finance.invoices.show', $invoice)->with('finance_status', sprintf(
            'Rejet de %s enregistré : il est désormais à la charge du patient, qui doit %s.',
            Money::format($rejection->amount),
            Money::format($invoice->fresh()->balance()),
        ));
    }
}
