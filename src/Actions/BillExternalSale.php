<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
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
 * **Une vente peut donner plusieurs factures.** Une ordonnance n'est pas
 * couverte d'un bloc : l'assurance porte l'amoxicilline, une aide sociale
 * porte l'antipaludique, et le reste est à la charge du patient. Or une
 * facture porte un seul assureur, un seul statut de créance, un seul montant
 * réglé. Les lignes sont donc groupées par organisme, et chaque groupe donne
 * sa pièce — ce que fait aussi un hôpital qui réclame à deux payeurs.
 *
 * L'appel est idempotent : une même pièce d'origine ne donne qu'une facture
 * par organisme. Un module qui renvoie après une panne retrouve les siennes
 * au lieu d'en créer d'autres.
 */
final class BillExternalSale
{
    public const SOURCE_PHARMACIE = 'pharmacie';

    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly Auditor $auditor,
    ) {}

    /**
     * Les factures de cette vente, une par organisme, la part du patient
     * seul en dernier.
     *
     * @param  list<array{label: string, quantity: int, unit_price: int, insurer?: ?string, insurer_rate?: ?int}>  $lines
     * @return Collection<int, Invoice>
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
    ): Collection {
        $source = Text::clean($source) ?? '';
        $reference = Text::clean($reference) ?? '';

        if ($source === '' || $reference === '') {
            throw new FinanceRuleViolation("Une vente venue d'un module doit dire d'où elle vient et sous quelle référence.");
        }

        $patientId = Text::clean($patientId);
        $patientName = Text::clean($patientName);

        if ($patientId === null && $patientName === null) {
            throw new FinanceRuleViolation('Indiquez le patient : son identifiant, son nom, ou les deux.');
        }

        $rows = $this->rows($lines);

        // Ce que la piece annonce pour toutes ses lignes, quand elles ne
        // nomment pas d'organisme elles-memes : le cas d'un module qui ne
        // connait qu'un seul payeur.
        [$parDefaut, $tauxParDefaut] = $this->coverage($coverage);

        $groupes = $this->groupByInsurer($rows, $parDefaut, $tauxParDefaut);
        $factures = collect();

        foreach ($groupes as $groupe) {
            $factures->push($this->invoice(
                $source,
                $reference,
                $patientId,
                $patientName,
                $groupe['rows'],
                $groupe['insurer'],
                $coverage,
                $note,
                $actor,
            ));
        }

        return $factures;
    }

    /**
     * Les lignes rangées par organisme payeur.
     *
     * Celles que personne ne couvre finissent ensemble, en dernier : c'est la
     * pièce que le patient règle sans tiers.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{insurer: ?Insurer, rows: list<array<string, mixed>>}>
     */
    private function groupByInsurer(array $rows, ?Insurer $parDefaut, int $tauxParDefaut): array
    {
        $groupes = [];

        foreach ($rows as $row) {
            $nomme = $row['insurer'] ?? null;
            $insurer = $nomme === null ? $parDefaut : $this->findInsurer($nomme);
            $taux = $row['insurer_rate'] ?? ($nomme === null ? $tauxParDefaut : null);

            // Un organisme inconnu de Finance, ou un taux nul : la ligne
            // revient au patient. On ne l'invente pas, et on ne la perd pas.
            if ($insurer === null || (int) $taux <= 0) {
                $insurer = null;
                $taux = 0;
            }

            $clef = $insurer?->getKey() ?? 0;

            $groupes[$clef] ??= ['insurer' => $insurer, 'rows' => []];
            $groupes[$clef]['rows'][] = array_replace($row, [
                'insurer_rate' => max(0, min(100, (int) $taux)),
            ]);
        }

        // Le patient seul en dernier : c'est la piece qu'il emporte.
        uksort($groupes, static fn (int $a, int $b): int => [$a === 0, $a] <=> [$b === 0, $b]);

        return array_values($groupes);
    }

    /**
     * Une facture, pour un organisme et les lignes qu'il porte.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array{insurer?: ?string, rate?: ?int, reference?: ?string}|null  $coverage
     */
    private function invoice(
        string $source,
        string $reference,
        ?string $patientId,
        ?string $patientName,
        array $rows,
        ?Insurer $insurer,
        ?array $coverage,
        ?string $note,
        Authenticatable $actor,
    ): Invoice {
        $existing = Invoice::query()
            ->where('source', $source)
            ->where('source_reference', $reference)
            ->where('insurer_id', $insurer?->getKey())
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

        $rows = array_map(static function (array $row): array {
            $share = (int) round($row['amount'] * $row['insurer_rate'] / 100);

            return array_replace($row, [
                'insurer_share' => $share,
                'patient_share' => $row['amount'] - $share,
            ]);
        }, $rows);

        $total = array_sum(array_column($rows, 'amount'));

        if ($total <= 0) {
            throw new FinanceRuleViolation('Le montant de la facture doit être supérieur à zéro.');
        }

        $insurerShare = array_sum(array_column($rows, 'insurer_share'));

        // Le taux de la pièce est celui que portent ses lignes, rapporté au
        // total : écrire autre chose ferait mentir la facture sur elle-même.
        $rate = $total > 0 ? (int) round($insurerShare * 100 / $total) : 0;

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

            $invoice->lines()->createMany(array_map(static fn (array $row): array => [
                'act_id' => null,
                'analytic_center_id' => null,
                'label' => $row['label'],
                'quantity' => $row['quantity'],
                'unit_price' => $row['unit_price'],
                'amount' => $row['amount'],
                'insurer_rate' => $row['insurer_rate'],
                'insurer_share' => $row['insurer_share'],
                'patient_share' => $row['patient_share'],
            ], $rows));

            // Pris en charge à 100 % : le patient ne doit rien, et la facture
            // le dit sans qu'on invente un encaissement de zéro franc.
            $invoice->recalculate();

            $this->auditor->record(
                'invoice_created',
                $invoice,
                sprintf(
                    'Facture %s émise pour %s depuis %s (%s)%s : %s',
                    $invoice->number,
                    $patientName ?? $patientId,
                    $source,
                    $reference,
                    $insurer === null ? '' : ', à la charge de '.$insurer->name,
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
     * @param  list<array{label: string, quantity: int, unit_price: int, insurer?: ?string, insurer_rate?: ?int}>  $lines
     * @return list<array<string, mixed>>
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
                // L'organisme que cette ligne nomme, et le taux qu'il porte
                // sur elle. Nuls, la ligne suit ce que la pièce annonce.
                'insurer' => Text::clean($line['insurer'] ?? null),
                'insurer_rate' => isset($line['insurer_rate']) ? max(0, min(100, (int) $line['insurer_rate'])) : null,
            ];
        }

        return $rows;
    }

    /**
     * L'organisme annoncé pour toute la pièce, et son taux.
     *
     * @param  array{insurer?: ?string, rate?: ?int, reference?: ?string}|null  $coverage
     * @return array{0: ?Insurer, 1: int}
     */
    private function coverage(?array $coverage): array
    {
        $name = Text::clean($coverage['insurer'] ?? null);
        $rate = (int) ($coverage['rate'] ?? 0);

        if ($name === null) {
            return [null, 0];
        }

        if ($rate > 100) {
            throw new FinanceRuleViolation('Une prise en charge ne dépasse pas 100 %.');
        }

        return [$this->findInsurer($name), max(0, $rate)];
    }

    /**
     * L'organisme que ce nom désigne chez Finance.
     *
     * Le module transmet un nom ou un code, pas une clé : il ne connaît pas
     * la table des assureurs. Un organisme inconnu n'est pas une raison de
     * refuser la vente, mais on ne l'invente pas non plus : la ligne revient
     * à la charge du patient, et l'audit garde le nom annoncé.
     */
    private function findInsurer(string $name): ?Insurer
    {
        return Insurer::query()->active()
            ->where(fn ($query) => $query->where('name', $name)->orWhere('code', $name))
            ->first();
    }
}
