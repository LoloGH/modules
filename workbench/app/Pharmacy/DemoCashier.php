<?php

declare(strict_types=1);

namespace Workbench\App\Pharmacy;

use Keneya\Pharmacie\Contracts\SaleSink;
use Keneya\Pharmacie\Contracts\SaleStatusProvider;
use Keneya\Pharmacie\Sales\DispensedSale;
use Keneya\Pharmacie\Sales\SaleStatus;

/**
 * Une caisse de démonstration, pour voir le parcours complet sans WorkFlow.
 *
 * Dans l'application hôte, ce rôle revient à Finance : la facture y est
 * créée, le patient règle sa part au guichet, et la pharmacie relit l'état.
 * Ici, une pièce est numérotée et son état vit dans un fichier, de façon à
 * survivre d'une requête à l'autre comme le ferait une vraie caisse.
 *
 * Pour simuler l'encaissement depuis le navigateur : /dev/caisse/{piece}.
 */
final class DemoCashier implements SaleSink, SaleStatusProvider
{
    public function send(DispensedSale $sale): ?string
    {
        $pieces = $this->read();
        $reference = sprintf('FAC-%d-%06d', (int) date('Y'), count($pieces) + 1);

        $pieces[$reference] = [
            'total' => $sale->total,
            'patient_due' => $this->patientDue($sale),
            'covered' => $sale->total - $this->patientDue($sale),
            'insurer' => $sale->coverage['insurer'] ?? null,
            'cancelled' => false,
        ];

        $this->write($pieces);

        return $reference;
    }

    public function status(string $reference): ?SaleStatus
    {
        $piece = $this->read()[$reference] ?? null;

        if ($piece === null) {
            return null;
        }

        return new SaleStatus(
            reference: $reference,
            total: (int) $piece['total'],
            patientDue: (int) $piece['patient_due'],
            patientPaid: (int) $piece['total'] - (int) $piece['covered'] - (int) $piece['patient_due'],
            coveredShare: (int) $piece['covered'],
            insurerName: $piece['insurer'],
            cancelled: (bool) $piece['cancelled'],
        );
    }

    /**
     * Le geste du caissier : la part du patient est réglée.
     */
    public function collect(string $reference): bool
    {
        $pieces = $this->read();

        if (! isset($pieces[$reference])) {
            return false;
        }

        $pieces[$reference]['patient_due'] = 0;
        $this->write($pieces);

        return true;
    }

    /**
     * Ce que le patient doit : le total moins la part prise en charge.
     *
     * La vraie caisse fait ce calcul avec ses propres règles ; celle-ci se
     * contente du taux transmis.
     */
    private function patientDue(DispensedSale $sale): int
    {
        $rate = (int) ($sale->coverage['rate'] ?? 0);

        if ($rate <= 0 || $rate > 100) {
            return $sale->total;
        }

        return (int) round($sale->total * (100 - $rate) / 100);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function read(): array
    {
        $fichier = $this->path();

        if (! is_file($fichier)) {
            return [];
        }

        return (array) json_decode((string) file_get_contents($fichier), true);
    }

    /**
     * @param  array<string, array<string, mixed>>  $pieces
     */
    private function write(array $pieces): void
    {
        file_put_contents($this->path(), json_encode($pieces, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function path(): string
    {
        return dirname(__DIR__, 3).'/database/demo-caisse.json';
    }
}
