<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Keneya\FinanceCaisse\Audit\Auditor;
use Keneya\FinanceCaisse\Exceptions\FinanceRuleViolation;
use Keneya\FinanceCaisse\Models\Act;
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
     */
    public function handle(?string $patientId, ?string $patientName, array $lines, ?string $note, Authenticatable $actor): Invoice
    {
        $patientId = Text::clean($patientId);
        $patientName = Text::clean($patientName);

        if ($patientId === null && $patientName === null) {
            throw new FinanceRuleViolation('Indiquez le patient : son identifiant, son nom, ou les deux.');
        }

        if ($lines === []) {
            throw new FinanceRuleViolation('Une facture doit avoir au moins une ligne.');
        }

        return DB::transaction(function () use ($patientId, $patientName, $lines, $note, $actor): Invoice {
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

            $invoice = Invoice::create([
                'number' => $this->numbers->next('invoice'),
                'patient_id' => $patientId,
                'patient_name' => $patientName,
                'status' => Invoice::STATUS_UNPAID,
                'total' => $total,
                'paid' => 0,
                'note' => Text::clean($note),
                'created_by_id' => Actor::id($actor),
                'created_by_name' => Actor::name($actor),
            ]);

            $invoice->lines()->createMany($rows);

            $this->auditor->record(
                'invoice_created',
                $invoice,
                sprintf('Facture %s émise pour %s : %s', $invoice->number, $patientName ?? $patientId, Money::format($total)),
                [],
                ['total' => $total, 'patient_id' => $patientId, 'lines' => count($rows)],
                $actor,
            );

            return $invoice;
        });
    }
}
