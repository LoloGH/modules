<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Keneya\FinanceCaisse\Audit\Auditor;
use Keneya\FinanceCaisse\Exceptions\FinanceRuleViolation;
use Keneya\FinanceCaisse\Finance;
use Keneya\FinanceCaisse\Models\Invoice;
use Keneya\FinanceCaisse\Queue\CashQueue;
use Keneya\FinanceCaisse\Queue\QueuedVisit;
use Keneya\FinanceCaisse\Queue\SettledPayment;
use Keneya\FinanceCaisse\Support\Money;

/**
 * Laisser passer un patient qui ne doit rien.
 *
 * Pris en charge à 100 %, ou déjà réglé, un patient n'a rien à payer, et un
 * encaissement de zéro franc n'existe pas : ce serait une ligne de caisse
 * fausse. Sans ce geste, il resterait pourtant planté devant le guichet, sa
 * visite bloquée à la caisse.
 *
 * Le caissier le libère donc explicitement. Rien n'entre dans le tiroir, la
 * visite poursuit son parcours chez l'hôte, et l'audit garde qui l'a laissé
 * passer et sur quelle pièce.
 *
 * La condition est vérifiée ici, jamais supposée : il faut une facture
 * rattachée, ouverte, dont le solde patient est nul. Un patient qui doit
 * encore quelque chose ne sort pas par cette porte.
 */
final class ReleaseQueuedVisit
{
    public function __construct(private readonly Auditor $auditor) {}

    public function handle(string $queueRef, string $visitRef, Authenticatable $cashier): QueuedVisit
    {
        $visit = Finance::cashQueue()->findVisit($queueRef, $visitRef);

        if ($visit === null) {
            throw new FinanceRuleViolation("Ce patient n'attend plus d'encaissement à cette caisse.");
        }

        if ($visit->invoiceId === null) {
            throw new FinanceRuleViolation(
                "Aucune facture ne dit que ce patient ne doit rien : encaissez, ou renvoyez-le d'où il vient."
            );
        }

        $invoice = Invoice::query()->find($visit->invoiceId);

        if ($invoice === null || $invoice->isClosed()) {
            throw new FinanceRuleViolation("La facture de ce passage n'est plus encaissable.");
        }

        if ($invoice->balance() > 0) {
            throw new FinanceRuleViolation(sprintf(
                'Ce patient doit encore %s sur la facture %s : il ne passe pas sans régler.',
                Money::format($invoice->balance()),
                $invoice->number,
            ));
        }

        $queueName = collect(Finance::cashQueue()->queues())
            ->first(fn (CashQueue $queue): bool => $queue->ref === $queueRef)?->name;

        DB::transaction(function () use ($visit, $invoice, $queueRef, $queueName, $cashier): void {
            Finance::visitAdvancer()->advanceAfterPayment($visit->ref, new SettledPayment(
                number: $invoice->number,
                amount: 0,
                actName: $visit->label(),
                queueRef: $queueRef,
                queueName: $queueName,
                cashierName: (string) (data_get($cashier, 'name') ?? data_get($cashier, 'email') ?? ''),
            ));

            $this->auditor->record(
                'queued_visit_released',
                $invoice,
                sprintf(
                    'Facture %s : rien à encaisser (%s pris en charge ou déjà réglé). %s poursuit son parcours.',
                    $invoice->number,
                    Money::format((int) $invoice->total),
                    $visit->patientName,
                ),
                [],
                [
                    'invoice' => $invoice->number,
                    'patient_id' => $visit->patientRef,
                    'queue' => $queueName,
                    'visit_ref' => $visit->ref,
                ],
                $cashier,
            );
        });

        return $visit;
    }
}
