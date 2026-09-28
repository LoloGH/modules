<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Keneya\Pharmacie\Actions\DispenseProducts;
use Keneya\Pharmacie\Actions\PrepareDispensation;
use Keneya\Pharmacie\Actions\SendToCashier;
use Keneya\Pharmacie\Models\Dispensation;
use Keneya\Pharmacie\Models\Location;
use Keneya\Pharmacie\Models\Product;
use Keneya\Pharmacie\Pharmacie;
use Keneya\Pharmacie\Prescriptions\Prescription;
use Keneya\Pharmacie\Services\PrescriptionMatcher;
use Keneya\Pharmacie\Services\StockPicker;
use Keneya\Pharmacie\Support\Text;

/**
 * Le comptoir : délivrer, et retrouver ce qui a été délivré.
 *
 * L'écran propose d'office le lot qui périme le premier ; le préparateur n'a
 * rien à chercher. Ce qui manque devient un reliquat visible, et la pièce
 * s'imprime.
 */
final class DispensingController extends PharmacieController
{
    private const PER_PAGE = 30;

    /**
     * Combien de lignes vides l'ecran propose quand rien ne les remplit,
     * et combien il en ajoute apres une ordonnance.
     */
    private const BLANK_LINES = 3;

    private const EXTRA_LINES = 2;

    public function index(Request $request): View
    {
        $search = Text::clean(is_string($request->query('q')) ? $request->query('q') : null);
        $status = in_array($request->query('statut'), ['partielles', 'annulees'], true) ? (string) $request->query('statut') : null;

        return view('pharmacie::dispensing.index', [
            'dispensations' => Dispensation::query()->ofFacility()
                ->when($search, function ($query) use ($search): void {
                    $like = '%'.$search.'%';
                    $query->where(fn ($where) => $where
                        ->where('number', 'like', $like)
                        ->orWhere('patient_name', 'like', $like)
                        ->orWhere('patient_id', 'like', $like)
                        ->orWhere('prescription_ref', 'like', $like));
                })
                ->when($status === 'partielles', fn ($query) => $query->where('outstanding', '>', 0)->where('status', '!=', Dispensation::STATUS_CANCELLED))
                ->when($status === 'annulees', fn ($query) => $query->where('status', Dispensation::STATUS_CANCELLED))
                ->with(['items', 'location'])
                ->latest('id')
                ->paginate(self::PER_PAGE)
                ->withQueryString(),
            'search' => $search,
            'status' => $status,
            'stats' => [
                'today' => Dispensation::query()->ofFacility()->dispensed()->whereDate('dispensed_at', today())->count(),
                'partial' => Dispensation::query()->ofFacility()->dispensed()->where('outstanding', '>', 0)->count(),
            ],
        ]);
    }

    /**
     * Le comptoir : le formulaire de dispensation, avec les lots proposés.
     */
    public function create(Request $request, StockPicker $picker, PrescriptionMatcher $matcher): View|RedirectResponse
    {
        // Venu de la file alors qu'une préparation l'attend : on l'y emmène
        // plutôt que de lui faire ressaisir ce qui est déjà écrit.
        $pending = $this->pendingPreparation($request);

        if ($pending !== null) {
            return redirect()->route('pharmacie.preparations.show', $pending)->with(
                'pharmacie_status',
                sprintf('La préparation %s attend déjà ce patient : il reste à la délivrer.', $pending->number),
            );
        }

        $location = $this->currentLocation($request);
        $products = Product::query()->ofFacility()->active()->get();

        // Pour chaque produit, ce que le système propose : le lot qui périme
        // en premier, et ce qui est disponible.
        $suggestions = [];

        if ($location !== null) {
            foreach ($products as $product) {
                $rows = $picker->batchesFor($product, $location);

                $suggestions[$product->id] = [
                    'batch' => $rows[0]['batch'] ?? null,
                    'available' => array_sum(array_column($rows, 'available')),
                    'batches' => $rows,
                ];
            }
        }

        // Venu de la file : patient et ordonnance pré-remplis.
        $queued = $this->queuedPatient($request);
        $prescription = $this->prescription($request, $queued);

        $locations = Location::query()->ofFacility()->active()->get();

        // Où dort chaque produit : sans cela, le comptoir annonce une
        // rupture alors que la réserve en a trois boîtes.
        $elsewhere = $this->elsewhere($products, $locations, $location, $picker);

        return view('pharmacie::dispensing.create', [
            'products' => $products,
            'locations' => $locations,
            'location' => $location,
            'suggestions' => $suggestions,
            'elsewhere' => $elsewhere,
            'queued' => $queued,
            'prescription' => $prescription,
            // Les lignes de l'écran : celles de l'ordonnance quand il y en a
            // une, sinon des lignes vides.
            'rows' => $this->lines($prescription, $location, $matcher, $elsewhere),
            // Le bouton ne dit pas la meme chose selon le parcours, et le
            // preparateur doit savoir ce qu'il declenche.
            'paymentFirst' => $this->paymentFirst(),
        ]);
    }

    /**
     * La préparation qui attend déjà le patient venu de la file, s'il y en a
     * une : c'est elle qu'il faut délivrer, pas une seconde.
     */
    private function pendingPreparation(Request $request): ?Dispensation
    {
        $patient = $request->query('patient');

        if (! is_string($patient) || $patient === '') {
            return null;
        }

        return Dispensation::query()->ofFacility()
            ->where('queue_ref', $patient)
            ->where('status', Dispensation::STATUS_DRAFT)
            ->latest('id')
            ->first();
    }

    /**
     * Ce qui est disponible ailleurs qu'ici, produit par produit.
     *
     * Le comptoir ne tient pas tout : un vaccin est dans la chaîne du froid,
     * ce qui n'est pas descendu attend à la centrale. Annoncer « rien en
     * stock » serait faux, et faire recommencer toute la dispensation à un
     * autre emplacement serait pénible. La ligne dira donc où aller le
     * chercher.
     *
     * @param  Collection<int, Product>  $products
     * @param  Collection<int, Location>  $locations
     * @return array<int, list<array{location: Location, available: int}>>
     */
    private function elsewhere($products, $locations, ?Location $here, StockPicker $picker): array
    {
        if ($products->isEmpty() || $locations->isEmpty()) {
            return [];
        }

        $stock = $picker->availabilityByLocation($products->pluck('id')->all());
        $byId = $locations->keyBy('id');
        $rows = [];

        foreach ($stock as $productId => $perLocation) {
            arsort($perLocation);

            foreach ($perLocation as $locationId => $available) {
                // Ici, ce n'est pas « ailleurs » : la ligne le sait déjà.
                if ($here !== null && (int) $locationId === (int) $here->id) {
                    continue;
                }

                $location = $byId->get($locationId);

                if ($location !== null) {
                    $rows[(int) $productId][] = ['location' => $location, 'available' => $available];
                }
            }
        }

        return $rows;
    }

    /**
     * Les lignes que l'écran affiche.
     *
     * Une ordonnance arrive avec ses lignes : le comptoir ne les ressaisit
     * pas, il les corrige. Ce qui a déjà été servi est déduit, et quelques
     * lignes vides restent pour ce que le pharmacien ajoute.
     *
     * @return list<array<string, mixed>>
     */
    private function lines(?Prescription $prescription, ?Location $location, PrescriptionMatcher $matcher, array $elsewhere = []): array
    {
        if ($prescription === null) {
            return array_fill(0, self::BLANK_LINES, $this->blankLine());
        }

        $rows = [];

        foreach ($matcher->lines($prescription, $location) as $row) {
            $line = $row['line'];
            // Le prescripteur donne une posologie et une duree, pas toujours
            // une quantite. Sans chiffre, c'est au pharmacien de la fixer :
            // l'ecran laisse la case vide plutot que d'ecrire zero.
            $remaining = $line->quantity > 0 ? max(0, $line->quantity - $row['served']) : null;
            $posology = $line->posology();

            $available = (int) $row['available'];
            $from = null;

            // Rien ici, mais quelque chose ailleurs : on propose d'emblée d'y
            // aller. Laisser la ligne rouge et vide obligerait le pharmacien
            // à deviner, puis à corriger la quantité à la main.
            if ($available <= 0 && $row['product'] !== null) {
                $best = $elsewhere[$row['product']->id][0] ?? null;

                if ($best !== null) {
                    $from = $best['location'];
                    $available = (int) $best['available'];
                }
            }

            $rows[] = [
                'label' => $line->label,
                'product_id' => $row['product']?->id,
                'prescribed' => $remaining,
                'quantity' => $remaining === null ? null : min($remaining, $available),
                'posology' => $posology === '-' ? null : $posology,
                'available' => $available,
                // L'emplacement d'où cette ligne sera prise, quand ce n'est
                // pas celui du comptoir.
                'from' => $from,
                'batches' => $from === null ? $row['batches'] : null,
                'served' => (int) $row['served'],
                'substitutable' => $line->substitutable,
                'matched' => $row['product'] !== null,
            ];
        }

        return array_merge($rows, array_fill(0, self::EXTRA_LINES, $this->blankLine()));
    }

    /**
     * @return array<string, mixed>
     */
    private function blankLine(): array
    {
        return [
            'label' => null,
            'product_id' => null,
            'prescribed' => null,
            'quantity' => null,
            'posology' => null,
            'available' => null,
            'from' => null,
            'batches' => null,
            'served' => 0,
            'substitutable' => true,
            'matched' => true,
        ];
    }

    /**
     * L'ordonnance à servir : celle du patient appelé, ou celle que l'adresse
     * désigne. Si le dossier médical ne la donne pas, l'écran reste vide
     * plutôt que d'inventer des lignes.
     *
     * @param  array{ref: string, ref_file: string, patient_id: string, patient_name: string, prescription: ?string}|null  $queued
     */
    private function prescription(Request $request, ?array $queued): ?Prescription
    {
        $reference = $queued['prescription'] ?? null;

        if ($reference === null && is_string($request->query('ordonnance'))) {
            $reference = (string) $request->query('ordonnance');
        }

        return $reference === null ? null : Pharmacie::prescriptions()->find($reference);
    }

    public function store(Request $request, DispenseProducts $action, PrepareDispensation $preparation): RedirectResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'integer', 'exists:pharmacie_locations,id'],
            'patient_id' => ['nullable', 'string', 'max:64'],
            'patient_name' => ['nullable', 'string', 'max:191'],
            'queue_ref' => ['nullable', 'string', 'max:64'],
            'prescription_ref' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'coverage_insurer' => ['nullable', 'string', 'max:191'],
            'coverage_rate' => ['nullable', 'integer', 'min:1', 'max:100'],
            'coverage_reference' => ['nullable', 'string', 'max:64'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['nullable', 'integer', 'exists:pharmacie_products,id'],
            'lines.*.quantity' => ['nullable', 'integer', 'min:0'],
            'lines.*.prescribed_quantity' => ['nullable', 'integer', 'min:0'],
            'lines.*.posology' => ['nullable', 'string', 'max:191'],
            'lines.*.location_id' => ['nullable', 'integer', 'exists:pharmacie_locations,id'],
            'lines.*.batch_id' => ['nullable', 'integer', 'exists:pharmacie_batches,id'],
            'lines.*.override_reason' => ['nullable', 'string', 'max:500'],
            'lines.*.comment' => ['nullable', 'string', 'max:500'],
        ]);

        // Les lignes sans produit ou sans quantite ne sont pas delivrees :
        // sur une ordonnance, elles restent simplement a servir.
        $lines = array_values(array_filter(
            $data['lines'],
            static fn (array $line): bool => ($line['product_id'] ?? null) !== null && (int) ($line['quantity'] ?? 0) > 0,
        ));

        if ($lines === []) {
            return back()->withInput()->with(
                'pharmacie_error',
                'Aucune ligne a delivrer : choisissez au moins un produit et une quantite.',
            );
        }

        $location = Location::query()->findOrFail((int) $data['location_id']);

        $rows = array_values(array_map(static fn (array $line): array => [
            'product_id' => (int) $line['product_id'],
            'quantity' => (int) $line['quantity'],
            'prescribed_quantity' => isset($line['prescribed_quantity']) ? (int) $line['prescribed_quantity'] : (int) $line['quantity'],
            'posology' => $line['posology'] ?? null,
            'location_id' => isset($line['location_id']) && $line['location_id'] !== null ? (int) $line['location_id'] : null,
            'batch_id' => isset($line['batch_id']) && $line['batch_id'] !== null ? (int) $line['batch_id'] : null,
            'override_reason' => $line['override_reason'] ?? null,
            'comment' => $line['comment'] ?? null,
        ], $lines));

        $details = [
            'patient_id' => $data['patient_id'] ?? null,
            'patient_name' => $data['patient_name'] ?? null,
            'queue_ref' => $data['queue_ref'] ?? null,
            'prescription_ref' => $data['prescription_ref'] ?? null,
            'source' => isset($data['queue_ref']) && $data['queue_ref'] !== null
                ? Dispensation::SOURCE_QUEUE
                : (isset($data['prescription_ref']) && $data['prescription_ref'] !== null
                    ? Dispensation::SOURCE_PRESCRIPTION
                    : Dispensation::SOURCE_COUNTER),
            'notes' => $data['notes'] ?? null,
        ];

        // Deux parcours, et c'est l'etablissement qui choisit le sien. Quand
        // le patient regle avant d'etre servi, le comptoir ne delivre pas :
        // il prepare, et la facture part en caisse.
        if ($this->paymentFirst()) {
            $prepared = $preparation->handle($location, $rows, $this->user($request), $details + [
                'coverage' => [
                    'insurer' => $data['coverage_insurer'] ?? null,
                    'rate' => isset($data['coverage_rate']) ? (int) $data['coverage_rate'] : null,
                    'reference' => $data['coverage_reference'] ?? null,
                ],
            ]);

            return redirect()->route('pharmacie.preparations.show', $prepared)->with(
                'pharmacie_status',
                $prepared->billing_reference === null
                    ? sprintf('Préparation %s enregistrée, mais la caisse n\'a pas répondu : renvoyez la facture.', $prepared->number)
                    : sprintf(
                        'Préparation %s envoyée en caisse (pièce %s). Le patient sera servi une fois sa part réglée.',
                        $prepared->number,
                        $prepared->billing_reference,
                    ),
            );
        }

        $dispensation = $action->handle($location, $rows, $this->user($request), $details);

        // Le dossier medical apprend ce qui a ete servi sur son ordonnance.
        $action->reportToPrescriber($dispensation);

        return redirect()->route('pharmacie.dispensing.show', $dispensation)->with(
            'pharmacie_status',
            $dispensation->isPartial()
                ? sprintf('Dispensation %s enregistrée, avec un reliquat de %d unité(s).', $dispensation->number, $dispensation->outstanding)
                : sprintf('Dispensation %s enregistrée.', $dispensation->number),
        );
    }

    /**
     * L'etablissement demande-t-il le paiement avant la delivrance ?
     */
    private function paymentFirst(): bool
    {
        return (bool) config('pharmacie.dispensing.payment_before_delivery', true);
    }

    public function show(Dispensation $dispensation): View
    {
        return view('pharmacie::dispensing.show', [
            'dispensation' => $dispensation->load(['items.product', 'items.batches.batch', 'location']),
            'facility' => Pharmacie::facility(),
            'billingKinds' => SendToCashier::kindLabels(),
        ]);
    }

    /**
     * Le bon de sortie : ce que le patient emporte, et ce que la pharmacie
     * garde comme preuve de ce qu'elle a délivré.
     */
    public function print(Dispensation $dispensation): View
    {
        return view('pharmacie::print.dispensation', [
            'dispensation' => $dispensation->load(['items.batches.batch', 'location']),
            'facility' => Pharmacie::facility(),
        ]);
    }

    /**
     * Envoyer a la caisse ce qui doit etre paye, ou ecrire que rien ne sera
     * demande, et a quel titre.
     */
    public function bill(Request $request, Dispensation $dispensation, SendToCashier $action): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $billed = $action->handle($dispensation, (string) $data['kind'], $this->user($request), $data['note'] ?? null);

        return redirect()->route('pharmacie.dispensing.show', $billed)->with(
            'pharmacie_status',
            $billed->payment_status === Dispensation::PAYMENT_FREE
                ? sprintf('Dispensation %s : gratuite, rien ne sera demande.', $billed->number)
                : sprintf('Dispensation %s envoyee a la caisse.', $billed->number),
        );
    }

    public function cancel(Request $request, Dispensation $dispensation, DispenseProducts $action): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $cancelled = $action->cancel($dispensation, (string) $data['reason'], $this->user($request));

        return redirect()->route('pharmacie.dispensing.show', $cancelled)->with(
            'pharmacie_status',
            sprintf('Dispensation %s annulée : le stock est revenu.', $cancelled->number),
        );
    }

    private function currentLocation(Request $request): ?Location
    {
        $id = $request->query('emplacement');

        if (ctype_digit((string) $id)) {
            return Location::query()->ofFacility()->active()->whereKey((int) $id)->first() ?? Location::default();
        }

        return Location::default();
    }

    /**
     * Le patient venu de la file, s'il y en a un : on pré-remplit plutôt que
     * de faire ressaisir.
     *
     * @return array{ref: string, ref_file: string, patient_id: string, patient_name: string, prescription: ?string}|null
     */
    private function queuedPatient(Request $request): ?array
    {
        $queueRef = $request->query('file');
        $patientRef = $request->query('patient');

        if (! is_string($queueRef) || ! is_string($patientRef)) {
            return null;
        }

        $patient = Pharmacie::queue()->findPatient($queueRef, $patientRef);

        return $patient === null ? null : [
            'ref' => $patient->ref,
            'ref_file' => $queueRef,
            'patient_id' => $patient->patientId,
            'patient_name' => $patient->patientName,
            'prescription' => $patient->prescriptionRef,
        ];
    }
}
