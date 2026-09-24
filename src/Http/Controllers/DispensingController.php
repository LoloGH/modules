<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Keneya\Pharmacie\Actions\DispenseProducts;
use Keneya\Pharmacie\Actions\SendToCashier;
use Keneya\Pharmacie\Models\Dispensation;
use Keneya\Pharmacie\Models\Location;
use Keneya\Pharmacie\Models\Product;
use Keneya\Pharmacie\Pharmacie;
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
    public function create(Request $request, StockPicker $picker): View
    {
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

        return view('pharmacie::dispensing.create', [
            'products' => $products,
            'locations' => Location::query()->ofFacility()->active()->get(),
            'location' => $location,
            'suggestions' => $suggestions,
            // Venu de la file : patient et ordonnance pré-remplis.
            'queued' => $this->queuedPatient($request),
        ]);
    }

    public function store(Request $request, DispenseProducts $action): RedirectResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'integer', 'exists:pharmacie_locations,id'],
            'patient_id' => ['nullable', 'string', 'max:64'],
            'patient_name' => ['nullable', 'string', 'max:191'],
            'queue_ref' => ['nullable', 'string', 'max:64'],
            'prescription_ref' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['nullable', 'integer', 'exists:pharmacie_products,id'],
            'lines.*.quantity' => ['nullable', 'integer', 'min:0'],
            'lines.*.prescribed_quantity' => ['nullable', 'integer', 'min:0'],
            'lines.*.posology' => ['nullable', 'string', 'max:191'],
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

        $dispensation = $action->handle(
            Location::query()->findOrFail((int) $data['location_id']),
            array_values(array_map(static fn (array $line): array => [
                'product_id' => (int) $line['product_id'],
                'quantity' => (int) $line['quantity'],
                'prescribed_quantity' => isset($line['prescribed_quantity']) ? (int) $line['prescribed_quantity'] : (int) $line['quantity'],
                'posology' => $line['posology'] ?? null,
                'batch_id' => isset($line['batch_id']) && $line['batch_id'] !== null ? (int) $line['batch_id'] : null,
                'override_reason' => $line['override_reason'] ?? null,
                'comment' => $line['comment'] ?? null,
            ], $lines)),
            $this->user($request),
            [
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
            ],
        );

        // Le dossier medical apprend ce qui a ete servi sur son ordonnance.
        $action->reportToPrescriber($dispensation);

        return redirect()->route('pharmacie.dispensing.show', $dispensation)->with(
            'pharmacie_status',
            $dispensation->isPartial()
                ? sprintf('Dispensation %s enregistrée, avec un reliquat de %d unité(s).', $dispensation->number, $dispensation->outstanding)
                : sprintf('Dispensation %s enregistrée.', $dispensation->number),
        );
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
     * @return array{ref: string, patient_id: string, patient_name: string, prescription: ?string}|null
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
            'patient_id' => $patient->patientId,
            'patient_name' => $patient->patientName,
            'prescription' => $patient->prescriptionRef,
        ];
    }
}
