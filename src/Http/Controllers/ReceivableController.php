<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Keneya\FinanceCaisse\Models\Insurer;
use Keneya\FinanceCaisse\Models\Invoice;
use Keneya\FinanceCaisse\Support\Text;

/**
 * Créances : ce que les patients et les assureurs doivent encore, facture par
 * facture, avec l'échéance et l'ancienneté.
 *
 * Échéance = date d'émission + délai (`finance.receivables.*_due_days`).
 * Une créance est « à échoir » jusqu'à ce jour, « échue » ensuite. Lecture
 * seule : on encaisse depuis la facture, on règle l'assurance depuis la
 * facture.
 */
final class ReceivableController extends FinanceController
{
    private const PER_PAGE = 50;

    public const TYPE_PATIENTS = 'patients';

    public const TYPE_INSURERS = 'assurances';

    /** Tranches d'ancienneté, en jours depuis l'émission. */
    public const AGING = ['0 – 30 jours' => [0, 30], '31 – 60 jours' => [31, 60], '61 – 90 jours' => [61, 90], 'Plus de 90 jours' => [91, null]];

    public function index(Request $request): View
    {
        $type = $request->query('type') === self::TYPE_INSURERS ? self::TYPE_INSURERS : self::TYPE_PATIENTS;
        $status = in_array($request->query('statut'), ['a-echoir', 'echue'], true) ? (string) $request->query('statut') : null;
        $search = Text::clean(is_string($request->query('q')) ? $request->query('q') : null);
        $insurerId = ctype_digit((string) $request->query('assureur')) ? (int) $request->query('assureur') : null;
        $kind = array_key_exists((string) $request->query('nature'), Insurer::kindLabels()) ? (string) $request->query('nature') : null;

        $days = $this->dueDays($type);
        $cutoff = Carbon::today()->subDays($days);

        $base = ($type === self::TYPE_INSURERS ? $this->insurerDebts() : $this->patientDebts())
            ->when($type === self::TYPE_INSURERS && $insurerId, fn (Builder $q) => $q->where('insurer_id', $insurerId))
            ->when($type === self::TYPE_INSURERS && $kind, fn (Builder $q) => $q->whereHas('insurer', fn (Builder $i) => $i->where('kind', $kind)))
            ->when($search, function (Builder $q) use ($search): void {
                $like = '%'.$search.'%';
                $q->where(fn (Builder $w) => $w->where('number', 'like', $like)
                    ->orWhere('patient_name', 'like', $like)
                    ->orWhere('patient_id', 'like', $like)
                    ->orWhere('policy_number', 'like', $like));
            });

        // Échue : émise avant le début du jour d'échéance dépassé.
        $filtered = (clone $base)
            ->when($status === 'echue', fn (Builder $q) => $q->where('created_at', '<', $cutoff))
            ->when($status === 'a-echoir', fn (Builder $q) => $q->where('created_at', '>=', $cutoff));

        $all = (clone $base)->get();
        $balance = fn (Invoice $invoice): int => $type === self::TYPE_INSURERS ? $invoice->insurerOutstanding() : $invoice->balance();

        $aging = [];
        foreach (self::AGING as $label => [$min, $max]) {
            $aging[$label] = (int) $all->filter(function (Invoice $invoice) use ($min, $max): bool {
                $age = (int) $invoice->created_at->copy()->startOfDay()->diffInDays(Carbon::today());

                return $age >= $min && ($max === null || $age <= $max);
            })->sum($balance);
        }

        return view('finance::receivables.index', [
            'type' => $type,
            'status' => $status,
            'search' => $search,
            'insurerId' => $insurerId,
            'kind' => $kind,
            'insurers' => Insurer::query()->orderBy('name')->get(),
            'days' => $days,
            'invoices' => $filtered->with('insurer')->oldest('created_at')->oldest('id')
                ->paginate(self::PER_PAGE)->withQueryString(),
            'aging' => $aging,
            'totals' => [
                'patients' => (int) $this->patientDebts()->get()->sum(fn (Invoice $i): int => $i->balance()),
                'insurers' => (int) $this->insurerDebts()->get()->sum(fn (Invoice $i): int => $i->insurerOutstanding()),
                // Créances des organismes, par nature.
                'byKind' => $this->insurerDebts()->with('insurer')->get()
                    ->groupBy(fn (Invoice $i): string => $i->insurer?->kindLabel() ?? '—')
                    ->map(fn ($group): int => (int) $group->sum(fn (Invoice $i): int => $i->insurerOutstanding()))
                    ->all(),
                'overdue' => (int) $all->filter(fn (Invoice $i): bool => $i->created_at->lt($cutoff))->sum($balance),
                'count' => $all->count(),
            ],
            'dueDate' => fn (Invoice $invoice): Carbon => $invoice->created_at->copy()->startOfDay()->addDays($days),
        ]);
    }

    /**
     * Factures dont le patient doit encore quelque chose.
     *
     * @return Builder<Invoice>
     */
    private function patientDebts(): Builder
    {
        return Invoice::query()
            ->whereNotIn('status', [Invoice::STATUS_CANCELLED, Invoice::STATUS_REFUNDED])
            ->whereRaw('patient_share + insurer_rejected > paid');
    }

    /**
     * Factures dont l'assureur doit encore quelque chose.
     *
     * @return Builder<Invoice>
     */
    private function insurerDebts(): Builder
    {
        return Invoice::query()
            ->whereNotNull('insurer_id')
            ->whereNotIn('status', [Invoice::STATUS_CANCELLED, Invoice::STATUS_REFUNDED])
            ->whereRaw('insurer_share > insurer_paid + insurer_rejected');
    }

    private function dueDays(string $type): int
    {
        $key = $type === self::TYPE_INSURERS ? 'insurer_due_days' : 'patient_due_days';

        return max(0, (int) config("finance.receivables.{$key}", $type === self::TYPE_INSURERS ? 30 : 0));
    }
}
