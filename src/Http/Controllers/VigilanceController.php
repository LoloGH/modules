<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Keneya\Pharmacie\Actions\RecallBatch;
use Keneya\Pharmacie\Actions\ReportAdverseEvent;
use Keneya\Pharmacie\Models\AdverseEvent;
use Keneya\Pharmacie\Models\Batch;
use Keneya\Pharmacie\Models\Dispensation;
use Keneya\Pharmacie\Models\Product;
use Keneya\Pharmacie\Models\Recall;
use Keneya\Pharmacie\Models\RecallPatient;
use Keneya\Pharmacie\Models\Stock;
use Keneya\Pharmacie\Models\StockMovement;

/**
 * Surveillance : le registre des produits sous contrôle, les rappels de lots
 * et les signalements d'effet indésirable.
 *
 * Trois écrans qui répondent à trois questions : où est passé chaque unité
 * d'un stupéfiant, qui a reçu un lot devenu suspect, et qu'a-t-on fait d'un
 * effet indésirable qu'on nous a rapporté.
 */
final class VigilanceController extends PharmacieController
{
    /**
     * Le registre des produits sous surveillance : chaque entrée, chaque
     * sortie, dans l'ordre, avec le solde après. Il se lit du grand livre :
     * il n'y a pas de seconde comptabilité à tenir à jour, donc rien qui
     * puisse diverger.
     */
    public function register(Request $request): View
    {
        $products = Product::query()->ofFacility()
            ->where('is_controlled', true)
            ->orderBy('name')
            ->get();

        $productId = (int) $request->query('product_id', (string) ($products->first()->id ?? 0));
        $product = $products->firstWhere('id', $productId);

        $movements = $product === null
            ? collect()
            : StockMovement::query()->ofFacility()
                ->where('product_id', $product->id)
                ->with(['batch', 'location'])
                ->orderBy('id')
                ->get();

        // Le solde se cumule dans l'ordre des écritures, puis la liste est
        // retournée : on lit un registre du plus récent au plus ancien, mais
        // un solde ne se calcule que dans l'autre sens.
        $balances = self::runningBalances($movements);

        return view('pharmacie::vigilance.register', [
            'products' => $products,
            'product' => $product,
            'movements' => $movements->reverse()->values(),
            'balances' => $balances,
            'onHand' => $product === null
                ? 0
                : (int) Stock::query()->ofFacility()->where('product_id', $product->id)->sum('quantity'),
            'kinds' => StockMovement::kindLabels(),
        ]);
    }

    // ------------------------------------------------- Rappels de lots

    public function recalls(): View
    {
        return view('pharmacie::vigilance.recalls', [
            'recalls' => Recall::query()->ofFacility()
                ->with(['batch.product', 'patients'])
                ->latest('id')
                ->paginate(20),
            'batches' => Batch::query()->ofFacility()
                ->with('product')
                ->whereNot('status', Batch::STATUS_DESTROYED)
                ->orderByDesc('id')
                ->limit(300)
                ->get(),
            'origins' => Recall::originLabels(),
            'levels' => Recall::levelLabels(),
        ]);
    }

    public function recall(Recall $recall): View
    {
        return view('pharmacie::vigilance.recall', [
            'recall' => $recall->load(['batch.product', 'patients.dispensation']),
        ]);
    }

    public function openRecall(Request $request, RecallBatch $action): RedirectResponse
    {
        $data = $request->validate([
            'batch_id' => ['required', 'integer', 'exists:pharmacie_batches,id'],
            'origin' => ['required', Rule::in(array_keys(Recall::originLabels()))],
            'level' => ['required', Rule::in(array_keys(Recall::levelLabels()))],
            'reference' => ['nullable', 'string', 'max:64'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $recall = $action->open(
            Batch::query()->findOrFail((int) $data['batch_id']),
            [
                'origin' => (string) $data['origin'],
                'level' => (string) $data['level'],
                'reference' => $data['reference'] ?? null,
                'reason' => (string) $data['reason'],
            ],
            $this->user($request),
        );

        return redirect()->route('pharmacie.vigilance.recalls.show', $recall)->with(
            'pharmacie_status',
            sprintf(
                'Rappel %s ouvert : le lot est bloqué, %d patient(s) ont reçu ce lot.',
                $recall->number,
                $recall->patients()->count(),
            ),
        );
    }

    public function contactPatient(Request $request, RecallPatient $line, RecallBatch $action): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:500']]);

        $action->contact($line, (string) $data['note'], $this->user($request));

        return redirect()->route('pharmacie.vigilance.recalls.show', $line->recall_id)
            ->with('pharmacie_status', sprintf('%s a été joint.', $line->label()));
    }

    public function closeRecall(Request $request, Recall $recall, RecallBatch $action): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:1000']]);

        $closed = $action->close($recall, (string) $data['note'], $this->user($request));

        return redirect()->route('pharmacie.vigilance.recalls.show', $closed)
            ->with('pharmacie_status', sprintf('Rappel %s clos.', $closed->number));
    }

    // ------------------------------------------------- Pharmacovigilance

    public function events(Request $request): View
    {
        $events = AdverseEvent::query()->ofFacility()
            ->with(['product', 'batch', 'dispensation'])
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('pharmacie::vigilance.events', [
            'events' => $events,
            'status' => (string) $request->query('status', ''),
            'statuses' => AdverseEvent::statusLabels(),
            'severities' => AdverseEvent::severityLabels(),
            'outcomes' => AdverseEvent::outcomeLabels(),
            'products' => Product::query()->ofFacility()->active()->orderBy('name')->get(),
            'batches' => Batch::query()->ofFacility()->with('product')->orderByDesc('id')->limit(300)->get(),
            'dispensations' => Dispensation::query()->ofFacility()
                ->where('status', Dispensation::STATUS_DISPENSED)
                ->latest('id')->limit(100)->get(),
            'open' => AdverseEvent::query()->ofFacility()->whereNot('status', AdverseEvent::STATUS_CLOSED)->count(),
            'serious' => AdverseEvent::query()->ofFacility()
                ->whereIn('severity', [AdverseEvent::SEVERITY_SEVERE, AdverseEvent::SEVERITY_LIFE_THREATENING])
                ->count(),
        ]);
    }

    public function event(AdverseEvent $event): View
    {
        // Les autres signalements qui désignent le même lot : c'est leur
        // nombre, plus que leur contenu, qui doit faire penser à un rappel.
        $siblings = $event->batch_id === null
            ? collect()
            : AdverseEvent::query()->ofFacility()
                ->where('batch_id', $event->batch_id)
                ->whereNot('id', $event->id)
                ->get();

        return view('pharmacie::vigilance.event', [
            'event' => $event->load(['product', 'batch', 'dispensation']),
            'siblings' => $siblings,
            'recall' => $event->batch_id === null
                ? null
                : Recall::query()->ofFacility()->where('batch_id', $event->batch_id)->latest('id')->first(),
        ]);
    }

    public function storeEvent(Request $request, ReportAdverseEvent $action): RedirectResponse
    {
        $data = $request->validate([
            'dispensation_id' => ['nullable', 'integer', 'exists:pharmacie_dispensations,id'],
            'product_id' => ['nullable', 'integer', 'exists:pharmacie_products,id'],
            'batch_id' => ['nullable', 'integer', 'exists:pharmacie_batches,id'],
            'patient_id' => ['nullable', 'string', 'max:64'],
            'patient_name' => ['nullable', 'string', 'max:191'],
            'description' => ['required', 'string', 'max:2000'],
            'severity' => ['required', Rule::in(array_keys(AdverseEvent::severityLabels()))],
            'started_on' => ['nullable', 'date'],
            'outcome' => ['required', Rule::in(array_keys(AdverseEvent::outcomeLabels()))],
            'action_taken' => ['nullable', 'string', 'max:1000'],
        ]);

        $event = $action->report($data, $this->user($request));

        return redirect()->route('pharmacie.vigilance.events.show', $event)->with(
            'pharmacie_status',
            sprintf('Signalement %s enregistré (%s).', $event->number, $event->severityLabel()),
        );
    }

    public function transmitEvent(Request $request, AdverseEvent $event, ReportAdverseEvent $action): RedirectResponse
    {
        $data = $request->validate(['transmitted_to' => ['required', 'string', 'max:191']]);

        $action->transmit($event, (string) $data['transmitted_to'], $this->user($request));

        return redirect()->route('pharmacie.vigilance.events.show', $event)
            ->with('pharmacie_status', sprintf('Signalement %s transmis.', $event->number));
    }

    public function closeEvent(Request $request, AdverseEvent $event, ReportAdverseEvent $action): RedirectResponse
    {
        $data = $request->validate(['conclusion' => ['required', 'string', 'max:1000']]);

        $action->close($event, (string) $data['conclusion'], $this->user($request));

        return redirect()->route('pharmacie.vigilance.events.show', $event)
            ->with('pharmacie_status', sprintf('Signalement %s clos.', $event->number));
    }

    /**
     * Le solde courant après chaque écriture, pour le registre : le grand
     * livre le stocke déjà par lot et par emplacement, mais le registre se
     * lit produit par produit, tous lots confondus.
     *
     * @param  iterable<StockMovement>  $movements
     * @return array<int, int>
     */
    public static function runningBalances(iterable $movements): array
    {
        $balance = 0;
        $balances = [];

        foreach ($movements as $movement) {
            $balance += (int) $movement->quantity;
            $balances[(int) $movement->id] = $balance;
        }

        return $balances;
    }
}
