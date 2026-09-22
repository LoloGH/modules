<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Actions;

use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Keneya\FinanceCaisse\Audit\Auditor;
use Keneya\FinanceCaisse\Exceptions\FinanceRuleViolation;
use Keneya\FinanceCaisse\Finance;
use Keneya\FinanceCaisse\Models\CashSession;
use Keneya\FinanceCaisse\Models\Payment;
use Keneya\FinanceCaisse\Models\PaymentMethod;
use Keneya\FinanceCaisse\Queue\CashQueue;
use Keneya\FinanceCaisse\Queue\SettledPayment;
use Keneya\FinanceCaisse\Support\Money;
use Throwable;

/**
 * Encaisse un patient appelé depuis la file, puis fait avancer sa visite chez
 * l'hôte (`VisitAdvancer`).
 *
 * L'ordre : l'encaissement d'abord (numéro, session, audit), l'avancement
 * ensuite, dans UNE transaction. Si l'hôte échoue, tout est annulé : pas
 * d'encaissement orphelin, pas de patient orienté sans avoir payé. L'échec est
 * journalisé (journal applicatif et audit de la session), puis rendu au
 * caissier comme une règle violée.
 *
 * Une visite déjà réglée, ou qui n'attend plus rien à cette caisse, est
 * refusée avant tout encaissement : pas de double paiement.
 */
final class CollectQueuedVisit
{
    public function __construct(
        private readonly RecordPayment $record,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  array{reference?: ?string, patient_id?: string|int|null, patient_name?: ?string, description?: ?string, act_id?: ?int}  $details
     */
    public function handle(
        CashSession $session,
        PaymentMethod $method,
        int $amount,
        Authenticatable $cashier,
        string $queueRef,
        string $visitRef,
        array $details = [],
    ): Payment {
        $visit = Finance::cashQueue()->findVisit($queueRef, $visitRef);

        if ($visit === null) {
            throw new FinanceRuleViolation("Ce patient n'attend plus d'encaissement à cette caisse.");
        }

        $already = Payment::query()
            ->where('host_visit_ref', $visitRef)
            ->where('status', Payment::STATUS_VALID)
            ->value('number');

        if ($already !== null) {
            throw new FinanceRuleViolation("Ce passage a déjà été encaissé ({$already}).");
        }

        $queueName = collect(Finance::cashQueue()->queues())
            ->first(fn (CashQueue $queue): bool => $queue->ref === $queueRef)?->name;

        try {
            return DB::transaction(function () use ($session, $method, $amount, $cashier, $queueRef, $queueName, $visitRef, $details): Payment {
                $payment = $this->record->handle($session, $method, $amount, $cashier, $details + ['host_visit_ref' => $visitRef]);

                Finance::visitAdvancer()->advanceAfterPayment($visitRef, new SettledPayment(
                    number: $payment->number,
                    amount: $payment->amount,
                    actName: $payment->act?->name,
                    queueRef: $queueRef,
                    queueName: $queueName,
                    cashierName: (string) (data_get($cashier, 'name') ?? data_get($cashier, 'email') ?? ''),
                ));

                return $payment;
            });
        } catch (FinanceRuleViolation $e) {
            throw $e;
        } catch (Throwable $e) {
            // L'encaissement a été annulé avec la transaction : on le dit, et
            // on laisse une trace hors de la transaction, qui elle survit.
            Log::error('Finance : encaissement annulé, la visite n\'a pas pu avancer chez l\'hôte.', [
                'visit_ref' => $visitRef,
                'queue_ref' => $queueRef,
                'cash_session' => $session->number,
                'amount' => $amount,
                'exception' => $e,
            ]);

            $this->auditor->record(
                'queued_payment_rolled_back',
                $session,
                sprintf(
                    'Encaissement de %s annulé pour la visite %s : son avancement a échoué (%s)',
                    Money::format($amount),
                    $visitRef,
                    $e->getMessage(),
                ),
                [],
                ['visit_ref' => $visitRef, 'queue_ref' => $queueRef, 'amount' => $amount],
                $cashier,
            );

            $reason = $e instanceof InvalidArgumentException || $e instanceof DomainException
                ? $e->getMessage()
                : 'une erreur est survenue.';

            throw new FinanceRuleViolation("L'encaissement n'a pas été enregistré : le patient n'a pas pu être orienté ({$reason})");
        }
    }
}
