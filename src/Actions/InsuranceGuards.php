<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Actions;

use Keneya\FinanceCaisse\Exceptions\FinanceRuleViolation;
use Keneya\FinanceCaisse\Models\Invoice;

/**
 * Règles communes aux règlements et rejets d'assurance.
 */
final class InsuranceGuards
{
    public static function assertInsuredAndOpen(Invoice $invoice): void
    {
        if (! $invoice->isInsured()) {
            throw new FinanceRuleViolation("La facture {$invoice->number} n'est prise en charge par aucun assureur.");
        }

        if ($invoice->isClosed()) {
            throw new FinanceRuleViolation("La facture {$invoice->number} est {$invoice->statusLabel()}.");
        }
    }
}
