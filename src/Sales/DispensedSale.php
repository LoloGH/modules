<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Sales;

/**
 * Ce que la pharmacie a délivré et qui reste à payer : le patient, la pièce
 * de la pharmacie, les lignes et le total.
 *
 * Volontairement pauvre : ni lot, ni péremption, ni stock. Ce qui sort d'ici
 * va à la caisse, pas au dossier pharmaceutique.
 *
 * Chaque ligne nomme son organisme payeur et le taux qu'il porte : une
 * assurance couvre l'antibiotique, une aide sociale l'antipaludique, et le
 * sirop reste au patient. Un organisme unique pour toute la pièce obligerait
 * à choisir entre trop couvrir et pas assez, et la caisse hériterait d'une
 * créance que l'organisme refuserait.
 *
 * @param  list<array{label: string, quantity: int, unit_price: int, amount: int, insurer?: ?string, insurer_rate?: int}>  $lines
 */
final readonly class DispensedSale
{
    /**
     * @param  list<array{label: string, quantity: int, unit_price: int, amount: int, insurer?: ?string, insurer_rate?: int}>  $lines
     */
    public function __construct(
        public string $reference,
        public string $patientId,
        public ?string $patientName,
        public array $lines,
        public int $total,
        public ?string $queueRef = null,
        /**
         * Le tiers payant, quand il y en a un : son nom, la part qu'il
         * prend en pourcentage, et la reference de l'accord.
         *
         * La pharmacie ne calcule aucune part : c'est la caisse qui decoupe
         * la facture. Elle transmet ce que le comptoir a constate.
         *
         * @var array{insurer?: ?string, rate?: ?int, reference?: ?string}|null
         */
        public ?array $coverage = null,
    ) {}
}
