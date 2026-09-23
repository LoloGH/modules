<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Keneya\FinanceCaisse\Models\CashSession;
use Keneya\FinanceCaisse\Models\Discount;
use Keneya\FinanceCaisse\Models\Invoice;
use Keneya\FinanceCaisse\Models\Refund;
use Keneya\FinanceCaisse\Support\Actor;
use Keneya\FinanceCaisse\Support\Alert;
use Keneya\FinanceCaisse\Support\Money;

/**
 * Les alertes du module : ce qui attend un geste, pour celui qui les lit.
 *
 * Aucune table de notifications, aucun envoi : chaque alerte est un état de
 * la base, relu à l'affichage. Une clôture validée, une créance réglée, un
 * tiroir vidé, et l'alerte disparaît d'elle-même.
 *
 * Chacune n'est calculée que si la personne a le droit d'agir dessus : on ne
 * signale jamais à quelqu'un un travail qu'il ne peut pas faire, et un lien
 * d'alerte ne mène jamais à un 403.
 */
final class AlertCenter
{
    public function __construct(private readonly CashSessionCalculator $calculator) {}

    /**
     * @return list<Alert>
     */
    public function for(?Authenticatable $user): array
    {
        if ($user === null) {
            return [];
        }

        $can = fn (string $ability): bool => Gate::forUser($user)->allows($ability);

        $alerts = array_merge(
            $can('finance.sessions.validate') ? $this->closures() : [],
            $can('finance.discounts.approve') ? $this->discounts() : [],
            $can('finance.refunds.approve') ? $this->refunds() : [],
            $can('finance.disbursements.create') ? $this->refundsToPay() : [],
            $can('finance.receivables.view') ? $this->receivables() : [],
            $can('finance.sessions.view') ? $this->ownSessions($user) : [],
        );

        usort($alerts, static fn (Alert $a, Alert $b): int => $a->weight() <=> $b->weight());

        return $alerts;
    }

    public function count(?Authenticatable $user): int
    {
        return count($this->for($user));
    }

    /**
     * Les clôtures qui attendent le contrôle, et celles dont l'écart n'a pas
     * encore été regardé.
     *
     * @return list<Alert>
     */
    private function closures(): array
    {
        $closed = CashSession::query()->where('status', CashSession::STATUS_CLOSED)->get();

        if ($closed->isEmpty()) {
            return [];
        }

        $alerts = [];
        $withVariance = $closed->filter(fn (CashSession $session): bool => (int) $session->variance !== 0);

        $alerts[] = new Alert(
            'sessions_to_validate',
            $withVariance->isEmpty() ? Alert::LEVEL_WARN : Alert::LEVEL_DANGER,
            sprintf('%d clôture(s) de caisse à valider', $closed->count()),
            $withVariance->isEmpty()
                ? 'Aucun écart signalé sur ces clôtures.'
                : sprintf(
                    '%d avec un écart, pour %s au total.',
                    $withVariance->count(),
                    Money::format((int) $withVariance->sum(fn (CashSession $session): int => abs((int) $session->variance))),
                ),
            route('finance.review.index'),
            'Valider',
        );

        return $alerts;
    }

    /**
     * @return list<Alert>
     */
    private function discounts(): array
    {
        $pending = Discount::query()->where('status', Discount::STATUS_REQUESTED)->get();

        if ($pending->isEmpty()) {
            return [];
        }

        return [new Alert(
            'discounts_pending',
            Alert::LEVEL_WARN,
            sprintf('%d remise(s) à approuver', $pending->count()),
            sprintf('%s demandés sur des factures.', Money::format((int) $pending->sum('amount'))),
            route('finance.credits.index'),
            'Examiner',
        )];
    }

    /**
     * @return list<Alert>
     */
    private function refunds(): array
    {
        $pending = Refund::query()->where('status', Refund::STATUS_REQUESTED)->get();

        if ($pending->isEmpty()) {
            return [];
        }

        return [new Alert(
            'refunds_pending',
            Alert::LEVEL_WARN,
            sprintf('%d remboursement(s) à approuver', $pending->count()),
            sprintf('%s demandés.', Money::format((int) $pending->sum('amount'))),
            route('finance.credits.index'),
            'Examiner',
        )];
    }

    /**
     * @return list<Alert>
     */
    private function refundsToPay(): array
    {
        $approved = Refund::query()->toPay()->get();

        if ($approved->isEmpty()) {
            return [];
        }

        return [new Alert(
            'refunds_to_pay',
            Alert::LEVEL_INFO,
            sprintf('%d remboursement(s) approuvé(s) à payer', $approved->count()),
            sprintf('%s à rendre aux patients, à la caisse.', Money::format((int) $approved->sum('amount'))),
            route('finance.cash.index'),
            'Payer',
        )];
    }

    /**
     * Les créances échues : facture émise avant sa date d'échéance dépassée.
     *
     * @return list<Alert>
     */
    private function receivables(): array
    {
        $alerts = [];

        foreach (['patients', 'insurers'] as $type) {
            $days = max(0, (int) config('finance.receivables.'.($type === 'insurers' ? 'insurer_due_days' : 'patient_due_days'), 0));
            $cutoff = Carbon::today()->subDays($days)->startOfDay();

            $invoices = Invoice::query()
                ->whereNotIn('status', [Invoice::STATUS_CANCELLED, Invoice::STATUS_REFUNDED])
                ->where('created_at', '<', $cutoff)
                ->when(
                    $type === 'insurers',
                    fn ($query) => $query->whereNotNull('insurer_id')->whereRaw('insurer_share > insurer_paid + insurer_rejected'),
                    fn ($query) => $query->whereRaw('patient_share + insurer_rejected - discount > paid'),
                )
                ->get();

            if ($invoices->isEmpty()) {
                continue;
            }

            $amount = (int) $invoices->sum(fn (Invoice $invoice): int => $type === 'insurers'
                ? $invoice->insurerOutstanding()
                : $invoice->balance());

            $alerts[] = new Alert(
                'receivables_'.$type,
                Alert::LEVEL_DANGER,
                $type === 'insurers'
                    ? sprintf('%d créance(s) d\'assureurs échue(s)', $invoices->count())
                    : sprintf('%d créance(s) de patients échue(s)', $invoices->count()),
                sprintf('%s attendus au-delà du délai de %d jour(s).', Money::format($amount), $days),
                route('finance.receivables.index', ['type' => $type, 'statut' => 'echue']),
                'Relancer',
            );
        }

        return $alerts;
    }

    /**
     * Ses propres tiroirs : une session oubliée ouverte, et un tiroir qui
     * dépasse le plafond que l'établissement s'est fixé.
     *
     * @return list<Alert>
     */
    private function ownSessions(Authenticatable $user): array
    {
        $sessions = CashSession::query()->open()->with('register')
            ->where('cashier_id', Actor::id($user))
            ->get();

        if ($sessions->isEmpty()) {
            return [];
        }

        $alerts = [];
        $hours = max(0, (int) config('finance.alerts.session_max_hours', 12));
        $ceiling = max(0, (int) config('finance.alerts.cash_ceiling', 0));

        if ($hours > 0) {
            $stale = $sessions->filter(fn (CashSession $session): bool => $session->opened_at !== null
                && $session->opened_at->diffInHours(now()) >= $hours);

            if ($stale->isNotEmpty()) {
                $alerts[] = new Alert(
                    'session_stale',
                    Alert::LEVEL_WARN,
                    sprintf('%d session(s) ouverte(s) depuis plus de %d heures', $stale->count(), $hours),
                    'Clôturez votre caisse pour que le contrôle puisse la valider : '
                        .$stale->map(fn (CashSession $session): string => (string) $session->register?->name)->implode(', '),
                    route('finance.cash.index'),
                    'Clôturer',
                );
            }
        }

        if ($ceiling > 0) {
            foreach ($sessions as $session) {
                $cash = $this->calculator->totals($session)['expected_cash'];

                if ($cash < $ceiling) {
                    continue;
                }

                $alerts[] = new Alert(
                    'cash_ceiling_'.$session->id,
                    Alert::LEVEL_DANGER,
                    sprintf('Tiroir %s au-dessus du plafond', $session->register?->name),
                    sprintf('%s en espèces, pour un plafond de %s : faites un dépôt.', Money::format($cash), Money::format($ceiling)),
                    route('finance.cash.sessions.show', $session),
                    'Ouvrir la caisse',
                );
            }
        }

        return $alerts;
    }
}
