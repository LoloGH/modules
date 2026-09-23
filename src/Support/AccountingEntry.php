<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Support;

use Illuminate\Support\Carbon;

/**
 * Une ligne d'écriture comptable : un compte, un sens, un montant.
 *
 * Le module ne tient pas la comptabilité de l'établissement : il traduit ses
 * propres écritures — encaissements, factures, avances, règlements — dans la
 * forme que le comptable attend, pour qu'il les reprenne dans son logiciel.
 * Rien n'est inventé : chaque ligne porte la pièce dont elle vient.
 */
final readonly class AccountingEntry
{
    public function __construct(
        public Carbon $date,
        public string $journal,
        public string $piece,
        public string $account,
        public string $label,
        public int $debit,
        public int $credit,
        public ?string $center = null,
    ) {}

    /**
     * @return list<string|int>
     */
    public function toRow(): array
    {
        return [
            $this->date->format('d/m/Y'),
            $this->journal,
            $this->piece,
            $this->account,
            $this->label,
            $this->debit,
            $this->credit,
            $this->center ?? '',
        ];
    }
}
