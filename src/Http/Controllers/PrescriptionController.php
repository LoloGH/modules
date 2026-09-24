<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Keneya\Pharmacie\Models\Batch;
use Keneya\Pharmacie\Models\Dispensation;
use Keneya\Pharmacie\Models\Location;
use Keneya\Pharmacie\Models\Product;
use Keneya\Pharmacie\Pharmacie;
use Keneya\Pharmacie\Prescriptions\NoPrescriptions;
use Keneya\Pharmacie\Prescriptions\Prescription;
use Keneya\Pharmacie\Prescriptions\PrescriptionLine;
use Keneya\Pharmacie\Services\StockPicker;

/**
 * Les ordonnances venues du dossier médical, et l'écran qui les sert.
 *
 * Le pharmacien ne ressaisit rien : chaque ligne prescrite arrive avec sa
 * posologie et, lorsque le DME donne un code produit, avec le produit du
 * catalogue déjà rapproché et son stock disponible.
 *
 * Ce qui reste à décider est ce qui demande un pharmacien : le produit quand
 * la correspondance n'est pas évidente, la quantité réellement servie, et la
 * substitution.
 */
final class PrescriptionController extends PharmacieController
{
    public function index(): View
    {
        $prescriptions = Pharmacie::prescriptions()->pending();

        return view('pharmacie::prescriptions.index', [
            'prescriptions' => $prescriptions,
            // Ce qui a déjà été servi sur ces ordonnances : une ordonnance
            // partiellement servie ne doit pas ressembler à une neuve.
            'served' => $this->alreadyServed($prescriptions),
            'connected' => ! Pharmacie::prescriptions() instanceof NoPrescriptions,
        ]);
    }

    /**
     * Préparer une ordonnance : les lignes prescrites, rapprochées du
     * catalogue et du stock.
     */
    public function show(Request $request, string $reference, StockPicker $picker): View
    {
        $prescription = Pharmacie::prescriptions()->find($reference);

        abort_if($prescription === null, 404, "Cette ordonnance n'existe pas, ou l'application hôte ne la fournit plus.");

        $location = Location::default();

        return view('pharmacie::prescriptions.show', [
            'prescription' => $prescription,
            'location' => $location,
            'locations' => Location::query()->ofFacility()->active()->get(),
            'products' => Product::query()->ofFacility()->active()->get(),
            'lines' => $this->matchLines($prescription, $location, $picker),
            'history' => Dispensation::query()->ofFacility()
                ->where('prescription_ref', $prescription->reference)
                ->with('items')
                ->latest('id')
                ->get(),
        ]);
    }

    /**
     * Rapproche chaque ligne prescrite du catalogue : le produit, ce qui est
     * disponible, et le lot que le système propose.
     *
     * @return list<array{line: PrescriptionLine, product: ?Product, available: int, batch: ?Batch, served: int}>
     */
    private function matchLines(Prescription $prescription, ?Location $location, StockPicker $picker): array
    {
        $served = $this->servedQuantities($prescription->reference);
        $rows = [];

        foreach ($prescription->lines as $line) {
            $product = $this->matchProduct($line);

            $available = 0;
            $batch = null;

            if ($product !== null && $location !== null) {
                $batches = $picker->batchesFor($product, $location);
                $available = array_sum(array_column($batches, 'available'));
                $batch = $batches[0]['batch'] ?? null;
            }

            $rows[] = [
                'line' => $line,
                'product' => $product,
                'available' => $available,
                'batch' => $batch,
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
    private function matchProduct(PrescriptionLine $line): ?Product
    {
        if ($line->productCode !== null) {
            $byCode = Product::query()->ofFacility()->active()->where('code', $line->productCode)->first();

            if ($byCode !== null) {
                return $byCode;
            }
        }

        return Product::query()->ofFacility()->active()
            ->where(fn ($query) => $query->where('name', $line->label)->orWhere('dci', $line->label))
            ->first();
    }

    /**
     * Ce qui a déjà été servi sur une ordonnance, par libellé de ligne.
     *
     * @return array<string, int>
     */
    private function servedQuantities(string $reference): array
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

    /**
     * Combien d'unités ont déjà été servies, ordonnance par ordonnance.
     *
     * @param  list<Prescription>  $prescriptions
     * @return array<string, int>
     */
    private function alreadyServed(array $prescriptions): array
    {
        if ($prescriptions === []) {
            return [];
        }

        return Dispensation::query()->ofFacility()
            ->whereIn('prescription_ref', array_map(static fn (Prescription $p): string => $p->reference, $prescriptions))
            ->where('status', '!=', Dispensation::STATUS_CANCELLED)
            ->with('items')
            ->get()
            ->groupBy('prescription_ref')
            ->map(fn ($group): int => (int) $group->sum(fn (Dispensation $d): int => (int) $d->items->sum('quantity')))
            ->all();
    }
}
