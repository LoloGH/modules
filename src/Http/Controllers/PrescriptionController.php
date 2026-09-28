<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Keneya\Pharmacie\Models\Dispensation;
use Keneya\Pharmacie\Models\Location;
use Keneya\Pharmacie\Models\Product;
use Keneya\Pharmacie\Pharmacie;
use Keneya\Pharmacie\Prescriptions\NoPrescriptions;
use Keneya\Pharmacie\Prescriptions\Prescription;
use Keneya\Pharmacie\Services\PrescriptionMatcher;

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
    public function show(Request $request, string $reference, PrescriptionMatcher $matcher): View
    {
        $prescription = Pharmacie::prescriptions()->find($reference);

        abort_if($prescription === null, 404, "Cette ordonnance n'existe pas, ou l'application hôte ne la fournit plus.");

        $location = Location::default();

        return view('pharmacie::prescriptions.show', [
            'prescription' => $prescription,
            'location' => $location,
            'locations' => Location::query()->ofFacility()->active()->get(),
            'products' => Product::query()->ofFacility()->active()->get(),
            'lines' => $matcher->lines($prescription, $location),
            'history' => Dispensation::query()->ofFacility()
                ->where('prescription_ref', $prescription->reference)
                ->with('items')
                ->latest('id')
                ->get(),
        ]);
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
