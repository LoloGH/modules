<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Keneya\FinanceCaisse\Models\CashSession;
use Keneya\FinanceCaisse\Models\Payment;
use Keneya\FinanceCaisse\Models\PaymentMethod;

/**
 * Totaux d'une session.
 *
 * Le montant théorique du tiroir ne compte que les ESPÈCES :
 * fonds initial + encaissements et avances en espèces - décaissements en
 * espèces. Une avance n'est pas une recette, mais l'argent est bien là.
 * Le Mobile Money, la carte, etc. sont totalisés par moyen mais n'entrent
 * pas dans le tiroir. Les opérations annulées sont exclues.
 */
final class CashSessionCalculator
{
    /**
     * @return array{
     *     opening_float: int,
     *     cash_in: int,
     *     cash_out: int,
     *     expected_cash: int,
     *     payments_count: int,
     *     disbursements_count: int,
     *     deposits_count: int,
     *     by_method: array<string, array{name: string, kind: string, in: int, out: int}>
     * }
     */
    public function totals(CashSession $session): array
    {
        $byMethod = [];
        $cashIn = 0;
        $cashOut = 0;
        $paymentsCount = 0;
        $disbursementsCount = 0;
        $depositsCount = 0;

        foreach ($this->grouped('finance_payments', $session) as $row) {
            $byMethod[$row->code] ??= ['name' => $row->name, 'kind' => $row->kind, 'in' => 0, 'out' => 0];
            $byMethod[$row->code]['in'] += (int) $row->total;
            $paymentsCount += (int) $row->n;

            if ($row->kind === PaymentMethod::KIND_CASH) {
                $cashIn += (int) $row->total;
            }
        }

        // Une avance entre dans le tiroir comme un encaissement : c'est de
        // l'argent reçu, même si ce n'est pas encore une recette.
        foreach ($this->grouped('finance_patient_deposits', $session) as $row) {
            $byMethod[$row->code] ??= ['name' => $row->name, 'kind' => $row->kind, 'in' => 0, 'out' => 0];
            $byMethod[$row->code]['in'] += (int) $row->total;
            $depositsCount += (int) $row->n;

            if ($row->kind === PaymentMethod::KIND_CASH) {
                $cashIn += (int) $row->total;
            }
        }

        foreach ($this->grouped('finance_disbursements', $session) as $row) {
            $byMethod[$row->code] ??= ['name' => $row->name, 'kind' => $row->kind, 'in' => 0, 'out' => 0];
            $byMethod[$row->code]['out'] += (int) $row->total;
            $disbursementsCount += (int) $row->n;

            if ($row->kind === PaymentMethod::KIND_CASH) {
                $cashOut += (int) $row->total;
            }
        }

        ksort($byMethod);

        $openingFloat = (int) $session->opening_float;

        return [
            'opening_float' => $openingFloat,
            'cash_in' => $cashIn,
            'cash_out' => $cashOut,
            'expected_cash' => $openingFloat + $cashIn - $cashOut,
            'payments_count' => $paymentsCount,
            'disbursements_count' => $disbursementsCount,
            'deposits_count' => $depositsCount,
            'by_method' => $byMethod,
        ];
    }

    /**
     * @return Collection<int, object>
     */
    private function grouped(string $table, CashSession $session): Collection
    {
        return DB::table("{$table} as t")
            ->join('finance_payment_methods as m', 'm.id', '=', 't.payment_method_id')
            ->where('t.cash_session_id', $session->getKey())
            ->where('t.status', Payment::STATUS_VALID)
            ->groupBy('m.id', 'm.code', 'm.name', 'm.kind')
            ->selectRaw('m.code, m.name, m.kind, SUM(t.amount) as total, COUNT(*) as n')
            ->get();
    }
}
