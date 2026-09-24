<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Services;

use Illuminate\Support\Carbon;
use Keneya\FinanceCaisse\Models\Disbursement;
use Keneya\FinanceCaisse\Models\Discount;
use Keneya\FinanceCaisse\Models\InsuranceRejection;
use Keneya\FinanceCaisse\Models\InsuranceSettlement;
use Keneya\FinanceCaisse\Models\Invoice;
use Keneya\FinanceCaisse\Models\PatientDeposit;
use Keneya\FinanceCaisse\Models\Payment;
use Keneya\FinanceCaisse\Support\AccountingEntry;
use Keneya\FinanceCaisse\Support\LedgerFilters;
use Keneya\FinanceCaisse\Support\Text;

/**
 * Les écritures du module, traduites en comptabilité.
 *
 * Le module ne tient pas la comptabilité : il donne au comptable, pour une
 * période, les écritures en partie double qu'il reprendra dans son logiciel.
 * Chaque ligne porte sa pièce (`FAC-…`, `PAI-…`) et son centre analytique.
 *
 * Les comptes ne sont jamais codés en dur : ils se règlent dans « Paramètres
 * financiers » (`accounting.accounts`), par catégorie de dépense
 * (`accounting.expense_accounts`) et par nature de moyen de paiement
 * (`accounting.method_accounts`). Un centre analytique peut porter son propre
 * compte de produits ; sinon le compte de produits par défaut s'applique.
 *
 * Ce que chaque fait produit :
 *
 *   - facture émise : client (patient, assureur) au débit, produits au crédit ;
 *   - encaissement : trésorerie au débit ; au crédit le client s'il solde une
 *     facture, sinon directement les produits (vente au comptant) ;
 *   - avance : trésorerie au débit, avances reçues au crédit ;
 *   - décaissement : charge au débit, trésorerie au crédit ;
 *   - règlement d'un assureur : trésorerie au débit, client assureur au crédit ;
 *   - rejet d'un assureur : le client patient prend la place du client assureur ;
 *   - remise accordée : remises au débit, client patient au crédit.
 *
 * Les mouvements annulés n'y figurent pas : une écriture annulée n'a jamais
 * existé pour la caisse, et ne doit pas partir en comptabilité.
 */
final class AccountingExport
{
    /**
     * @return list<AccountingEntry>
     */
    public function entries(LedgerFilters $filters): array
    {
        $entries = array_merge(
            $this->invoices($filters),
            $this->payments($filters),
            $this->deposits($filters),
            $this->disbursements($filters),
            $this->settlements($filters),
            $this->rejections($filters),
            $this->discounts($filters),
        );

        usort($entries, static fn (AccountingEntry $a, AccountingEntry $b): int => [$a->date->getTimestamp(), $a->piece, $a->debit === 0 ? 1 : 0]
            <=> [$b->date->getTimestamp(), $b->piece, $b->debit === 0 ? 1 : 0]);

        return $entries;
    }

    /**
     * La balance de la période : un compte, ce qu'il a reçu, ce qu'il a donné.
     *
     * @param  list<AccountingEntry>  $entries
     * @return list<array{account: string, label: string, debit: int, credit: int, balance: int}>
     */
    public function balance(array $entries): array
    {
        $accounts = [];

        foreach ($entries as $entry) {
            $accounts[$entry->account] ??= ['account' => $entry->account, 'label' => $entry->label, 'debit' => 0, 'credit' => 0, 'balance' => 0];
            $accounts[$entry->account]['debit'] += $entry->debit;
            $accounts[$entry->account]['credit'] += $entry->credit;
        }

        foreach ($accounts as $number => $row) {
            $accounts[$number]['balance'] = $row['debit'] - $row['credit'];
            // PHP transforme « 571 » en entier quand il sert de cle de
            // tableau : on le relit toujours comme un numero de compte.
            $accounts[$number]['label'] = $this->accountLabel((string) $number);
        }

        ksort($accounts);

        return array_values($accounts);
    }

    /**
     * @param  list<AccountingEntry>  $entries
     * @return array{debit: int, credit: int, balanced: bool}
     */
    public function totals(array $entries): array
    {
        $debit = array_sum(array_map(static fn (AccountingEntry $entry): int => $entry->debit, $entries));
        $credit = array_sum(array_map(static fn (AccountingEntry $entry): int => $entry->credit, $entries));

        return ['debit' => $debit, 'credit' => $credit, 'balanced' => $debit === $credit];
    }

    /**
     * Les factures émises : ce que le patient et l'organisme doivent, en
     * regard des produits de chaque acte.
     *
     * @return list<AccountingEntry>
     */
    private function invoices(LedgerFilters $filters): array
    {
        $entries = [];

        $invoices = Invoice::query()
            ->where('status', '!=', Invoice::STATUS_CANCELLED)
            ->whereBetween('created_at', [$filters->from, $filters->to])
            ->with(['lines.center', 'insurer'])
            ->get();

        foreach ($invoices as $invoice) {
            $label = 'Facture '.$invoice->number.' · '.($invoice->patient_name ?? $invoice->patient_id ?? 'Patient');

            if ((int) $invoice->patient_share > 0) {
                $entries[] = $this->debit($invoice->created_at, 'VE', $invoice->number, $this->account('patients'), $label, (int) $invoice->patient_share);
            }

            if ((int) $invoice->insurer_share > 0) {
                $entries[] = $this->debit(
                    $invoice->created_at,
                    'VE',
                    $invoice->number,
                    $this->account('insurers'),
                    $label.' · '.($invoice->insurer?->name ?? 'Organisme'),
                    (int) $invoice->insurer_share,
                );
            }

            foreach ($invoice->lines as $line) {
                $entries[] = $this->credit(
                    $invoice->created_at,
                    'VE',
                    $invoice->number,
                    $this->revenueAccount($line->center?->account_code),
                    $line->label,
                    (int) $line->amount,
                    $line->center?->name,
                );
            }
        }

        return $entries;
    }

    /**
     * Les encaissements : la trésorerie au débit ; au crédit le client si
     * l'encaissement solde une facture, sinon les produits.
     *
     * @return list<AccountingEntry>
     */
    private function payments(LedgerFilters $filters): array
    {
        $entries = [];

        $payments = $filters->payments()->where('status', Payment::STATUS_VALID)
            ->with(['method', 'center', 'invoice'])
            ->get();

        foreach ($payments as $payment) {
            $label = 'Encaissement '.$payment->number.($payment->patient_name ? ' · '.$payment->patient_name : '');
            $amount = (int) $payment->amount;

            $entries[] = $this->debit(
                $payment->created_at,
                'CA',
                $payment->number,
                $this->methodAccount($payment->method?->kind),
                $label,
                $amount,
            );

            $entries[] = $payment->invoice_id === null
                ? $this->credit(
                    $payment->created_at,
                    'CA',
                    $payment->number,
                    $this->revenueAccount($payment->center?->account_code),
                    $payment->description ?? $label,
                    $amount,
                    $payment->center?->name,
                )
                : $this->credit(
                    $payment->created_at,
                    'CA',
                    $payment->number,
                    $this->account('patients'),
                    'Facture '.$payment->invoice?->number,
                    $amount,
                );
        }

        return $entries;
    }

    /**
     * Les avances : de la trésorerie reçue qui reste due au patient.
     *
     * @return list<AccountingEntry>
     */
    private function deposits(LedgerFilters $filters): array
    {
        $entries = [];

        $deposits = PatientDeposit::query()->valid()
            ->whereBetween('created_at', [$filters->from, $filters->to])
            ->with('method')
            ->get();

        foreach ($deposits as $deposit) {
            $label = 'Avance '.$deposit->number.' · '.($deposit->patient_name ?? $deposit->patient_id);

            $entries[] = $this->debit($deposit->created_at, 'CA', $deposit->number, $this->methodAccount($deposit->method?->kind), $label, (int) $deposit->amount);
            $entries[] = $this->credit($deposit->created_at, 'CA', $deposit->number, $this->account('deposits'), $label, (int) $deposit->amount);
        }

        return $entries;
    }

    /**
     * Les décaissements : une charge, ou le remboursement d'une avance selon
     * le compte réglé pour la catégorie.
     *
     * @return list<AccountingEntry>
     */
    private function disbursements(LedgerFilters $filters): array
    {
        $entries = [];

        $disbursements = $filters->disbursements()->where('status', Disbursement::STATUS_VALID)
            ->with(['method', 'center'])
            ->get();

        foreach ($disbursements as $disbursement) {
            $label = 'Décaissement '.$disbursement->number.' · '.$disbursement->reason;

            $entries[] = $this->debit(
                $disbursement->created_at,
                'CA',
                $disbursement->number,
                $this->expenseAccount($disbursement->category),
                $label,
                (int) $disbursement->amount,
                $disbursement->center?->name,
            );

            $entries[] = $this->credit(
                $disbursement->created_at,
                'CA',
                $disbursement->number,
                $this->methodAccount($disbursement->method?->kind),
                $label,
                (int) $disbursement->amount,
            );
        }

        return $entries;
    }

    /**
     * @return list<AccountingEntry>
     */
    private function settlements(LedgerFilters $filters): array
    {
        $entries = [];

        $settlements = InsuranceSettlement::query()
            ->whereDate('received_on', '>=', $filters->from->toDateString())
            ->whereDate('received_on', '<=', $filters->to->toDateString())
            ->with(['insurer', 'invoice'])
            ->get();

        foreach ($settlements as $settlement) {
            $label = 'Règlement '.$settlement->number.' · '.($settlement->insurer?->name ?? 'Organisme');
            $date = $settlement->received_on;

            $entries[] = $this->debit($date, 'BQ', $settlement->number, $this->account('bank'), $label, (int) $settlement->amount);
            $entries[] = $this->credit($date, 'BQ', $settlement->number, $this->account('insurers'), $label.' · facture '.$settlement->invoice?->number, (int) $settlement->amount);
        }

        return $entries;
    }

    /**
     * Un rejet ne fait pas disparaître la créance : elle passe de l'organisme
     * au patient.
     *
     * @return list<AccountingEntry>
     */
    private function rejections(LedgerFilters $filters): array
    {
        $entries = [];

        $rejections = InsuranceRejection::query()
            ->whereBetween('created_at', [$filters->from, $filters->to])
            ->with(['insurer', 'invoice'])
            ->get();

        foreach ($rejections as $rejection) {
            $label = 'Rejet '.($rejection->insurer?->name ?? 'Organisme').' · facture '.$rejection->invoice?->number;
            $piece = (string) ($rejection->invoice?->number ?? $rejection->id);

            $entries[] = $this->debit($rejection->created_at, 'OD', $piece, $this->account('patients'), $label, (int) $rejection->amount);
            $entries[] = $this->credit($rejection->created_at, 'OD', $piece, $this->account('insurers'), $label, (int) $rejection->amount);
        }

        return $entries;
    }

    /**
     * Les remises approuvées : ce que l'établissement renonce à encaisser.
     *
     * @return list<AccountingEntry>
     */
    private function discounts(LedgerFilters $filters): array
    {
        $entries = [];

        $discounts = Discount::query()->approved()
            ->whereBetween('decided_at', [$filters->from, $filters->to])
            ->with('invoice')
            ->get();

        foreach ($discounts as $discount) {
            $label = 'Remise '.$discount->number.' · facture '.$discount->invoice?->number;
            $date = $discount->decided_at ?? $discount->created_at;

            $entries[] = $this->debit($date, 'OD', $discount->number, $this->account('discounts'), $label, (int) $discount->amount);
            $entries[] = $this->credit($date, 'OD', $discount->number, $this->account('patients'), $label, (int) $discount->amount);
        }

        return $entries;
    }

    private function debit(Carbon $date, string $journal, string $piece, string $account, string $label, int $amount, ?string $center = null): AccountingEntry
    {
        return new AccountingEntry($date->copy(), $journal, $piece, $account, $label, $amount, 0, $center);
    }

    private function credit(Carbon $date, string $journal, string $piece, string $account, string $label, int $amount, ?string $center = null): AccountingEntry
    {
        return new AccountingEntry($date->copy(), $journal, $piece, $account, $label, 0, $amount, $center);
    }

    private function account(string $key): string
    {
        return (string) config('finance.accounting.accounts.'.$key, '');
    }

    /**
     * Le compte de produits du centre analytique, ou celui par défaut.
     */
    private function revenueAccount(?string $centerAccount): string
    {
        return Text::clean($centerAccount) ?? $this->account('revenue');
    }

    private function methodAccount(?string $kind): string
    {
        $accounts = (array) config('finance.accounting.method_accounts', []);

        return (string) ($accounts[$kind] ?? $this->account('cash'));
    }

    private function expenseAccount(?string $category): string
    {
        $accounts = (array) config('finance.accounting.expense_accounts', []);

        return (string) ($accounts[$category] ?? $this->account('expenses'));
    }

    /**
     * Le libellé du compte dans la balance : celui que l'établissement a
     * réglé, sinon le numéro seul.
     */
    private function accountLabel(string $account): string
    {
        $labels = [];

        foreach ((array) config('finance.accounting.accounts', []) as $key => $number) {
            $labels[(string) $number] = self::ACCOUNT_LABELS[$key] ?? (string) $key;
        }

        return $labels[$account] ?? $account;
    }

    /** @var array<string, string> */
    private const ACCOUNT_LABELS = [
        'cash' => 'Caisse',
        'bank' => 'Banque',
        'patients' => 'Clients : patients',
        'insurers' => 'Clients : organismes',
        'deposits' => 'Avances reçues des patients',
        'revenue' => 'Produits des services',
        'discounts' => 'Remises accordées',
        'expenses' => 'Charges',
    ];
}
