<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Keneya\FinanceCaisse\Models\Invoice;
use Keneya\FinanceCaisse\Models\PatientDeposit;
use Keneya\FinanceCaisse\Models\Payment;
use Keneya\FinanceCaisse\Models\PaymentMethod;
use Keneya\FinanceCaisse\Support\Text;

/**
 * Le compte financier d'un patient : ce qu'il a versé d'avance, ce que ces
 * avances ont payé, et ce qu'il lui reste.
 *
 * Aucune table de solde : le solde est toujours recalculé des écritures, qui
 * seules font foi. Verser = une avance (`finance_patient_deposits`) ; puiser =
 * un encaissement réglé par le moyen « Compte patient ». Une avance annulée,
 * comme un encaissement annulé, sort du calcul.
 */
final class PatientAccount
{
    public function balance(string $patientId): int
    {
        $summary = $this->summary($patientId);

        return $summary['balance'];
    }

    /**
     * @return array{deposited: int, used: int, balance: int}
     */
    public function summary(string $patientId): array
    {
        $deposited = (int) PatientDeposit::query()->valid()->where('patient_id', $patientId)->sum('amount');
        $used = (int) $this->usedQuery()->where('patient_id', $patientId)->sum('amount');

        return ['deposited' => $deposited, 'used' => $used, 'balance' => $deposited - $used];
    }

    /**
     * Le relevé du compte : avances et utilisations, du plus récent au plus
     * ancien, annulations comprises — un compte se lit aussi par ce qui a été
     * corrigé.
     *
     * @return Collection<int, array{type: string, model: PatientDeposit|Payment}>
     */
    public function movements(string $patientId): Collection
    {
        $deposits = PatientDeposit::query()
            ->where('patient_id', $patientId)
            ->with(['method', 'session.register'])
            ->get()
            ->map(static fn (PatientDeposit $deposit): array => ['type' => 'deposit', 'model' => $deposit]);

        $uses = Payment::query()
            ->where('patient_id', $patientId)
            ->whereHas('method', fn ($query) => $query->where('kind', PaymentMethod::KIND_PATIENT_ACCOUNT))
            ->with(['method', 'act', 'invoice', 'session.register'])
            ->get()
            ->map(static fn (Payment $payment): array => ['type' => 'use', 'model' => $payment]);

        return $deposits->concat($uses)
            ->sortByDesc(static fn (array $row): array => [$row['model']->created_at->getTimestamp(), $row['model']->id])
            ->values();
    }

    /**
     * Ce que le patient doit encore sur ses factures : le compte se lit en
     * regard de ce qui reste dû.
     */
    public function invoices(string $patientId): Collection
    {
        return Invoice::query()
            ->where('patient_id', $patientId)
            ->where('status', '!=', Invoice::STATUS_CANCELLED)
            ->latest('id')
            ->get();
    }

    /**
     * Tous les comptes ouverts : un patient a un compte dès qu'il a versé une
     * avance. Le nom affiché est le dernier connu.
     *
     * @return list<array{patient_id: string, patient_name: ?string, deposited: int, used: int, balance: int}>
     */
    public function all(?string $search = null): array
    {
        $search = Text::clean($search);

        $deposits = PatientDeposit::query()->valid()
            ->when($search, fn ($query) => $this->searched($query, $search))
            ->get(['patient_id', 'patient_name', 'amount', 'id'])
            ->groupBy('patient_id');

        $used = $this->usedQuery()
            ->whereIn('patient_id', $deposits->keys()->all())
            ->get(['patient_id', 'amount'])
            ->groupBy('patient_id')
            ->map(static fn (Collection $rows): int => (int) $rows->sum('amount'));

        $accounts = $deposits->map(function (Collection $rows, string $patientId) use ($used): array {
            $deposited = (int) $rows->sum('amount');
            $spent = (int) ($used[$patientId] ?? 0);

            return [
                'patient_id' => $patientId,
                'patient_name' => $rows->sortByDesc('id')->first()?->patient_name,
                'deposited' => $deposited,
                'used' => $spent,
                'balance' => $deposited - $spent,
            ];
        })->values()->all();

        usort($accounts, static fn (array $a, array $b): int => $b['balance'] <=> $a['balance']);

        return $accounts;
    }

    /**
     * Les encaissements réglés sur le compte patient : ce qui puise dans les
     * avances.
     *
     * @return Builder<Payment>
     */
    private function usedQuery()
    {
        return Payment::query()
            ->where('status', Payment::STATUS_VALID)
            ->whereHas('method', fn ($query) => $query->where('kind', PaymentMethod::KIND_PATIENT_ACCOUNT));
    }

    /**
     * @param  Builder<PatientDeposit>  $query
     * @return Builder<PatientDeposit>
     */
    private function searched($query, string $search)
    {
        $like = '%'.$search.'%';

        return $query->where(fn ($where) => $where
            ->where('patient_id', 'like', $like)
            ->orWhere('patient_name', 'like', $like));
    }
}
