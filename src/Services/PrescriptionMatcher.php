<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Services;

use Keneya\Pharmacie\Models\Batch;
use Keneya\Pharmacie\Models\Dispensation;
use Keneya\Pharmacie\Models\Location;
use Keneya\Pharmacie\Models\Product;
use Keneya\Pharmacie\Prescriptions\Prescription;
use Keneya\Pharmacie\Prescriptions\PrescriptionLine;

/**
 * Rapproche une ordonnance du catalogue et du stock.
 *
 * Le dossier médical prescrit un nom, parfois un code ; la pharmacie tient
 * des produits et des lots. Ce service fait le pont, une fois, pour tous les
 * écrans qui servent une ordonnance : celui des ordonnances comme le
 * comptoir, quand le patient arrive de la file.
 *
 * Il rapproche, il ne décide pas : une ligne sans correspondance revient
 * vide, et c'est au pharmacien de choisir le produit ou de substituer.
 */
final class PrescriptionMatcher
{
    /** @var list<Product>|null */
    private ?array $catalogue = null;

    public function __construct(private readonly StockPicker $picker) {}

    /**
     * Chaque ligne prescrite, avec le produit rapproché, ce qui est
     * disponible à cet emplacement, et ce qui a déjà été servi.
     *
     * @return list<array{line: PrescriptionLine, product: ?Product, available: int, batch: ?Batch, batches: list<array{batch: Batch, available: int}>, served: int}>
     */
    public function lines(Prescription $prescription, ?Location $location): array
    {
        $served = $this->servedQuantities($prescription->reference);
        $rows = [];

        foreach ($prescription->lines as $line) {
            $product = $this->product($line);
            $batches = [];

            if ($product !== null && $location !== null) {
                $batches = $this->picker->batchesFor($product, $location);
            }

            $rows[] = [
                'line' => $line,
                'product' => $product,
                'available' => array_sum(array_column($batches, 'available')),
                'batch' => $batches[0]['batch'] ?? null,
                'batches' => $batches,
                'served' => $served[mb_strtolower($line->label)] ?? 0,
            ];
        }

        return $rows;
    }

    /**
     * Le produit du catalogue qui correspond : par code quand le DME le
     * donne, sinon par nom ou par DCI. Une correspondance douteuse ne force
     * rien, le pharmacien choisit.
     */
    public function product(PrescriptionLine $line): ?Product
    {
        if ($line->productCode !== null) {
            $byCode = Product::query()->ofFacility()->active()->where('code', $line->productCode)->first();

            if ($byCode !== null) {
                return $byCode;
            }
        }

        $exact = Product::query()->ofFacility()->active()
            ->where(fn ($query) => $query->where('name', $line->label)->orWhere('dci', $line->label))
            ->first();

        return $exact ?? $this->byDosedName($line->label);
    }

    /**
     * Le produit dont le nom ouvre la ligne prescrite, suivi d'un dosage.
     *
     * Le médecin écrit « Amoxicilline 500 mg », le catalogue tient
     * « Amoxicilline » : c'est le même produit. Ce qui suit le nom doit être
     * un chiffre, sinon « Eau » attraperait « Eau oxygénée ».
     *
     * Le nom commercial passe avant la DCI, car plusieurs formes d'un même
     * produit la partagent : la comprimé et la suspension sont toutes deux
     * de l'amoxicilline, et seul leur nom les distingue. À égalité, on ne
     * devine pas et le pharmacien choisit.
     */
    private function byDosedName(string $label): ?Product
    {
        return $this->opening($label, 'name') ?? $this->opening($label, 'dci');
    }

    /**
     * Le produit dont ce champ ouvre la ligne prescrite, le plus précis
     * d'abord, ou rien si deux se valent.
     */
    private function opening(string $label, string $field): ?Product
    {
        $cherche = mb_strtolower($label);
        $trouve = null;
        $longueur = 0;
        $egalite = false;

        foreach ($this->catalogue() as $product) {
            $nom = $product->{$field};

            if (! is_string($nom) || $nom === '' || ! str_starts_with($cherche, mb_strtolower($nom))) {
                continue;
            }

            $reste = trim(mb_substr($label, mb_strlen($nom)));

            if ($reste !== '' && ! ctype_digit(mb_substr($reste, 0, 1))) {
                continue;
            }

            if (mb_strlen($nom) > $longueur) {
                $trouve = $product;
                $longueur = mb_strlen($nom);
                $egalite = false;
            } elseif (mb_strlen($nom) === $longueur) {
                $egalite = true;
            }
        }

        return $egalite ? null : $trouve;
    }

    /**
     * Le catalogue actif, lu une fois : une ordonnance se rapproche ligne par
     * ligne, et rien ne justifie de le relire à chacune.
     *
     * @return list<Product>
     */
    private function catalogue(): array
    {
        return $this->catalogue ??= Product::query()->ofFacility()->active()->get()->all();
    }

    /**
     * Ce qui a déjà été servi sur une ordonnance, par libellé de ligne.
     *
     * @return array<string, int>
     */
    public function servedQuantities(string $reference): array
    {
        $totals = [];

        $dispensations = Dispensation::query()->ofFacility()
            ->where('prescription_ref', $reference)
            ->where('status', '!=', Dispensation::STATUS_CANCELLED)
            ->with('items')
            ->get();

        foreach ($dispensations as $dispensation) {
            foreach ($dispensation->items as $item) {
                $key = mb_strtolower((string) $item->label);
                $totals[$key] = ($totals[$key] ?? 0) + (int) $item->quantity;
            }
        }

        return $totals;
    }
}
