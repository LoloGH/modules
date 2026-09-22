<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Keneya\FinanceCaisse\Audit\Auditor;
use Keneya\FinanceCaisse\Exceptions\FinanceRuleViolation;
use Keneya\FinanceCaisse\Models\Act;
use Keneya\FinanceCaisse\Models\Insurer;
use Keneya\FinanceCaisse\Models\Invoice;
use Keneya\FinanceCaisse\Services\NumberGenerator;
use Keneya\FinanceCaisse\Support\Actor;
use Keneya\FinanceCaisse\Support\Money;
use Keneya\FinanceCaisse\Support\Text;

/**
 * Émet une facture à partir du catalogue : chaque ligne est un acte actif,
 * facturé à son tarif standard du jour, que l'on ne saisit pas — fixer un
 * prix est une décision de gestion, pas de guichet.
 */
final class CreateInvoice
{
    public const MAX_QUANTITY = 99;

    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  list<array{act_id: int, quantity: int}>  $lines
     * @param  array{insurer_id: int, rate: int, policy_number?: ?string}|null  $coverage  prise en charge par un assureur
     */
    public function handle(?string $patientId, ?string $patientName, array $lines, ?string $note, Authenticatable $actor, ?array $coverage = null): Invoice
    {
        $patientId = Text::clean($patientId);
        $patientName = Text::clean($patientName);

        if ($patientId === null && $patientName === null) {
            throw new FinanceRuleViolation('Indiquez le patient : son identifiant, son nom, ou les deux.');
        }

        if ($lines === []) {
            throw new FinanceRuleViolation('Une facture doit avoir au moins une ligne.');
        }

        $insurer = $this->insurer($coverage);

        return DB::transaction(function () use ($patientId, $patientName, $lines, $note, $actor, $coverage, $insurer): Invoice {
            $rows = [];

            foreach ($lines as $line) {
                $quantity = (int) $line['quantity'];

                if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
                    throw new FinanceRuleViolation(sprintf('La quantité doit être comprise entre 1 et %d.', self::MAX_QUANTITY));
                }

                $act = Act::query()->with('standardTariff')->find((int) $line['act_id']);

                if ($act === null || ! $act->is_active) {
                    throw new FinanceRuleViolation("Un acte choisi n'est plus proposé par le catalogue.");
                }

                if ($act->standardTariff === null) {
                    throw new FinanceRuleViolation("L'acte « {$act->name} » n'a pas de tarif : il ne peut pas être facturé.");
                }

                $unit = (int) $act->standardTariff->amount;

                $rows[] = [
                    'act_id' => $act->id,
                    'label' => $act->name,
                    'quantity' => $quantity,
                    'unit_price' => $unit,
                    'amount' => $unit * $quantity,
                ];
            }

            $total = array_sum(array_column($rows, 'amount'));

            if ($total <= 0) {
                throw new FinanceRuleViolation('Le montant de la facture doit être supérieur à zéro.');
            }

            // La part assurance est arrondie au franc ; le patient paie le reste.
            $rate = $insurer === null ? 0 : (int) $coverage['rate'];
            $insurerShare = (int) round($total * $rate / 100);

            $invoice = Invoice::create([
                'number' => $this->numbers->next('invoice'),
                'patient_id' => $patientId,
                'patient_name' => $patientName,
                'insurer_id' => $insurer?->id,
                'coverage_rate' => $rate,
                'policy_number' => $insurer === null ? null : Text::clean($coverage['policy_number'] ?? null),
                'status' => Invoice::STATUS_UNPAID,
                'claim_status' => $insurer === null ? null : Invoice::CLAIM_PENDING,
                'total' => $total,
                'insurer_share' => $insurerShare,
                'patient_share' => $total - $insurerShare,
                'paid' => 0,
                'note' => Text::clean($note),
                'created_by_id' => Actor::id($actor),
                'created_by_name' => Actor::name($actor),
            ]);

            $invoice->lines()->createMany($rows);

            // Pris en charge à 100 % : le patient ne doit rien.
            $invoice->recalculate();

            $this->auditor->record(
                'invoice_created',
                $invoice,
                sprintf('Facture %s émise pour %s : %s', $invoice->number, $patientName ?? $patientId, Money::format($total)),
                [],
                array_filter([
                    'total' => $total,
                    'patient_id' => $patientId,
                    'lines' => count($rows),
                    'insurer' => $insurer?->code,
                    'coverage_rate' => $insurer === null ? null : $rate,
                    'insurer_share' => $insurer === null ? null : $insurerShare,
                ], static fn ($value): bool => $value !== null),
                $actor,
            );

            return $invoice;
        });
    }

    /**
     * @param  array{insurer_id: int, rate: int, policy_number?: ?string}|null  $coverage
     */
    private function insurer(?array $coverage): ?Insurer
    {
        if ($coverage === null) {
            return null;
        }

        $insurer = Insurer::query()->find((int) $coverage['insurer_id']);

        if ($insurer === null || ! $insurer->is_active) {
            throw new FinanceRuleViolation("L'assureur choisi n'est pas actif.");
        }

        $rate = (int) $coverage['rate'];

        if ($rate < 1 || $rate > 100) {
            throw new FinanceRuleViolation('Le taux de prise en charge doit être compris entre 1 et 100 %.');
        }

        return $insurer;
    }
}
