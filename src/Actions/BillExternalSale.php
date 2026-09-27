<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Keneya\FinanceCaisse\Audit\Auditor;
use Keneya\FinanceCaisse\Exceptions\FinanceRuleViolation;
use Keneya\FinanceCaisse\Models\Insurer;
use Keneya\FinanceCaisse\Models\Invoice;
use Keneya\FinanceCaisse\Services\NumberGenerator;
use Keneya\FinanceCaisse\Support\Actor;
use Keneya\FinanceCaisse\Support\Money;
use Keneya\FinanceCaisse\Support\Text;

/**
 * Facture une vente déjà faite par un autre module de l'établissement.
 *
 * La pharmacie délivre des médicaments et connaît leurs prix ; elle ne tient
 * pas de tiroir. Elle demande donc à Finance de faire payer, et Finance émet
 * une facture ordinaire : le patient la règle à la caisse, elle apparaît dans
 * les factures, dans les créances, dans la comptabilité.
 *
 * Ce qui la distingue d'une facture de guichet : ses lignes ne viennent pas
 * du catalogue des actes. Fixer un prix reste une décision de gestion, mais
 * c'est celle de la pharmacie, tenue dans son propre catalogue. Finance ne
 * renégocie pas le prix d'une boîte déjà sortie du stock : il l'encaisse et
 * le trace, sans acte rattaché et avec l'origine écrite sur la pièce.
 *
 * L'appel est idempotent : une même pièce d'origine ne donne qu'une facture.
 * Un module qui renvoie après une panne retrouve la sienne au lieu d'en créer
 * une seconde.
 *
 * La prise en charge est celle que le module a constatée au comptoir : un
 * organisme, un taux, une référence d'accord. Le taux par acte de l'organisme
 * ne s'applique pas ici — il porte sur des actes du catalogue, et ces lignes
 * n'en sont pas.
 */
final class BillExternalSale
{
    public const SOURCE_PHARMACIE = 'pharmacie';

    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  list<array{label: string, quantity: int, unit_price: int}>  $lines
     * @param  array{insurer?: ?string, rate?: ?int, reference?: ?string}|null  $coverage
     */
    public function handle(
        string $source,
        string $reference,
        ?string $patientId,
        ?string $patientName,
        array $lines,
        Authenticatable $actor,
        ?array $coverage = null,
        ?string $note = null,
    ): Invoice {
        $source = Text::clean($source) ?? '';
        $reference = Text::clean($reference) ?? '';

        if ($source === '' || $reference === '') {
            throw new FinanceRuleViolation("Une vente venue d'un module doit dire d'où elle vient et sous quelle référence.");
        }

        $existing = Invoice::query()
            ->where('source', $source)
            ->where('source_reference', $reference)
            ->first();

        // Déjà facturée : on rend la pièce existante. Le module qui renvoie
        // après une panne retrouve la sienne, le patient n'en paie qu'une.
        if ($existing !== null && ! $existing->isClosed()) {
            return $existing;
        }

        if ($existing !== null) {
            throw new FinanceRuleViolation(
                "La pièce {$reference} a déjà donné la facture {$existing->number}, qui est {$existing->statusLabel()}."
            );
        }

        $patientId = Text::clean($patientId);
        $patientName = Text::clean($patientName);

        if ($patientId === null && $patientName === null) {
            throw new FinanceRuleViolation('Indiquez le patient : son identifiant, son nom, ou les deux.');
        }

        $rows = $this->rows($lines);
        $total = array_sum(array_column($rows, 'amount'));

        if ($total <= 0) {
            throw new FinanceRuleViolation('Le montant de la facture doit être supérieur à zéro.');
        }

        [$insurer, $rate] = $this->coverage($coverage);
        $insurerShare = (int) round($total * $rate / 100);

        return DB::transaction(function () use (
            $source, $reference, $patientId, $patientName, $rows, $total,
            $insurer, $rate, $insurerShare, $coverage, $note, $actor,
        ): Invoice {
            $invoice = Invoice::create([
                'number' => $this->numbers->next('invoice'),
                'patient_id' => $patientId,
                'patient_name' => $patientName,
                'source' => $source,
                'source_reference' => $reference,
                'insurer_id' => $insurer?->id,
                'coverage_rate' => $rate,
                'policy_number' => $insurer === null ? null : Text::clean($coverage['reference'] ?? null),
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

            $invoice->lines()->createMany(array_map(static function (array $row) use ($rate): array {
                $share = (int) round($row['amount'] * $rate / 100);

                return $row + [
                    'act_id' => null,
                    'analytic_center_id' => null,
                    'insurer_rate' => $rate,
                    'insurer_share' => $share,
                    'patient_share' => $row['amount'] - $share,
                ];
            }, $rows));

            // Pris en charge à 100 % : le patient ne doit rien, et la facture
            // le dit sans qu'on invente un encaissement de zéro franc.
            $invoice->recalculate();

            $this->auditor->record(
                'invoice_created',
                $invoice,
                sprintf(
                    'Facture %s émise pour %s depuis %s (%s) : %s',
                    $invoice->number,
                    $patientName ?? $patientId,
                    $source,
                    $reference,
                    Money::format($total),
                ),
                [],
                array_filter([
                    'total' => $total,
                    'patient_id' => $patientId,
                    'lines' => count($rows),
                    'source' => $source,
                    'source_reference' => $reference,
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
     * Les lignes, relues : un libellé, une quantité, un prix unitaire. Le
     * montant se recalcule ici ; on ne fait pas confiance à un total envoyé.
     *
     * @param  list<array{label: string, quantity: int, unit_price: int}>  $lines
     * @return list<array{label: string, quantity: int, unit_price: int, amount: int}>
     */
    private function rows(array $lines): array
    {
        if ($lines === []) {
            throw new FinanceRuleViolation('Une facture doit avoir au moins une ligne.');
        }

        $rows = [];

        foreach ($lines as $line) {
            $label = Text::clean($line['label'] ?? null);
            $quantity = (int) ($line['quantity'] ?? 0);
            $unit = (int) ($line['unit_price'] ?? 0);

            if ($label === null) {
                throw new FinanceRuleViolation('Chaque ligne facturée doit dire ce qui est vendu.');
            }

            if ($quantity < 1) {
                throw new FinanceRuleViolation("La quantité de « {$label} » doit être d'au moins une unité.");
            }

            if ($unit < 0) {
                throw new FinanceRuleViolation("Le prix de « {$label} » ne peut pas être négatif.");
            }

            $rows[] = [
                'label' => $label,
                'quantity' => $quantity,
                'unit_price' => $unit,
                'amount' => $unit * $quantity,
            ];
        }

        return $rows;
    }

    /**
     * L'organisme qui prend en charge, et le taux retenu.
     *
     * Le module transmet un nom, pas une clé : il ne connaît pas la table des
     * assureurs de Finance. Un organisme inconnu n'est pas une raison de
     * refuser la vente, mais on ne l'invente pas non plus : la facture reste
     * entièrement à la charge du patient, et l'audit garde le nom annoncé.
     *
     * @param  array{insurer?: ?string, rate?: ?int, reference?: ?string}|null  $coverage
     * @return array{0: ?Insurer, 1: int}
     */
    private function coverage(?array $coverage): array
    {
        $name = Text::clean($coverage['insurer'] ?? null);
        $rate = (int) ($coverage['rate'] ?? 0);

        if ($name === null || $rate <= 0) {
            return [null, 0];
        }

        if ($rate > 100) {
            throw new FinanceRuleViolation('Une prise en charge ne dépasse pas 100 %.');
        }

        $insurer = Insurer::query()->active()
            ->where(fn ($query) => $query->where('name', $name)->orWhere('code', $name))
            ->first();

        return $insurer === null ? [null, 0] : [$insurer, $rate];
    }
}
