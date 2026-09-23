<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Keneya\Pharmacie\Audit\Auditor;
use Keneya\Pharmacie\Models\Batch;
use Keneya\Pharmacie\Models\Location;
use Keneya\Pharmacie\Models\Product;
use Keneya\Pharmacie\Models\Stock;
use Keneya\Pharmacie\Models\StockMovement;
use Keneya\Pharmacie\Services\StockLedger;
use Keneya\Pharmacie\Support\Text;

/**
 * L'état du stock, produit par produit et lot par lot.
 *
 * Trois chiffres qui ne se confondent jamais : ce qu'il y a (physique), ce qui
 * est promis (réservé), ce qu'on peut servir (disponible). Et, sous chaque
 * lot, le grand livre de ses mouvements : d'où il vient, où il est allé.
 */
final class StockController extends PharmacieController
{
    private const PER_PAGE = 30;

    public function index(Request $request): View
    {
        $search = Text::clean(is_string($request->query('q')) ? $request->query('q') : null);
        $locationId = ctype_digit((string) $request->query('emplacement')) ? (int) $request->query('emplacement') : null;
        $alert = in_array($request->query('alerte'), ['rupture', 'seuil', 'peremption'], true) ? (string) $request->query('alerte') : null;

        $products = Product::query()->ofFacility()
            ->where('is_active', true)
            ->search($search)
            ->orderBy('name')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $rows = [];

        foreach ($products as $product) {
            $stocks = Stock::query()
                ->where('product_id', $product->id)
                ->when($locationId, fn ($query) => $query->where('location_id', $locationId))
                ->with(['batch', 'location'])
                ->get();

            $onHand = (int) $stocks->sum('quantity');
            $reserved = (int) $stocks->sum('reserved');

            $soon = $stocks->filter(fn (Stock $stock): bool => $stock->quantity > 0
                && $stock->batch !== null
                && $stock->batch->daysToExpiry() !== null
                && $stock->batch->daysToExpiry() <= $this->warningDays());

            $row = [
                'product' => $product,
                'on_hand' => $onHand,
                'reserved' => $reserved,
                'available' => max(0, $onHand - $reserved),
                'batches' => $stocks->filter(fn (Stock $stock): bool => $stock->quantity > 0)->count(),
                'expiring' => (int) $soon->sum('quantity'),
                'value' => (int) $stocks->sum(fn (Stock $stock): int => (int) $stock->quantity * (int) ($stock->batch?->purchase_price ?? 0)),
            ];

            $keep = match ($alert) {
                'rupture' => $row['available'] <= 0,
                'seuil' => $row['available'] <= (int) $product->min_threshold,
                'peremption' => $row['expiring'] > 0,
                default => true,
            };

            if ($keep) {
                $rows[] = $row;
            }
        }

        return view('pharmacie::stock.index', [
            'products' => $products,
            'rows' => $rows,
            'locations' => Location::query()->ofFacility()->active()->get(),
            'search' => $search,
            'locationId' => $locationId,
            'alert' => $alert,
            'totals' => [
                'value' => array_sum(array_column($rows, 'value')),
                'lines' => count($rows),
            ],
        ]);
    }

    /**
     * La fiche de stock d'un produit : ses lots, leurs péremptions, et ce
     * qu'il reste à chaque emplacement.
     */
    public function product(Product $product): View
    {
        return view('pharmacie::stock.product', [
            'product' => $product,
            'stocks' => Stock::query()
                ->where('product_id', $product->getKey())
                ->with(['batch', 'location'])
                ->get()
                ->sortBy(fn (Stock $stock): string => (string) ($stock->batch?->expires_on ?? '9999-12-31')),
            'warningDays' => $this->warningDays(),
        ]);
    }

    /**
     * Un lot et toute son histoire.
     */
    public function batch(Batch $batch): View
    {
        return view('pharmacie::stock.batch', [
            'batch' => $batch->load(['product', 'stocks.location']),
            'movements' => StockMovement::query()
                ->where('batch_id', $batch->getKey())
                ->with('location')
                ->latest('id')
                ->limit(200)
                ->get(),
        ]);
    }

    /**
     * Corriger une quantité : jamais sans motif, et toujours par une écriture
     * qui reste au grand livre.
     */
    public function adjust(Request $request, StockLedger $ledger, Auditor $auditor): RedirectResponse
    {
        $data = $request->validate([
            'batch_id' => ['required', 'integer', 'exists:pharmacie_batches,id'],
            'location_id' => ['required', 'integer', 'exists:pharmacie_locations,id'],
            'direction' => ['required', 'in:entree,sortie'],
            'quantity' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $batch = Batch::query()->findOrFail((int) $data['batch_id']);
        $location = Location::query()->findOrFail((int) $data['location_id']);
        $actor = $this->user($request);
        $quantity = (int) $data['quantity'];

        $movement = $data['direction'] === 'entree'
            ? $ledger->receive($batch, $location, $quantity, StockMovement::KIND_ADJUSTMENT, $actor, ['reason' => $data['reason']])
            : $ledger->issue($batch, $location, $quantity, StockMovement::KIND_ADJUSTMENT, $actor, ['reason' => $data['reason']]);

        $auditor->record(
            'stock_adjusted',
            $batch,
            sprintf(
                'Ajustement du lot %s à %s : %+d (%s)',
                $batch->number,
                $location->name,
                $movement->quantity,
                $data['reason'],
            ),
            [],
            ['batch' => $batch->number, 'location' => $location->code, 'quantity' => $movement->quantity, 'reason' => $data['reason']],
            $actor,
        );

        return redirect()->route('pharmacie.stock.batches.show', $batch)->with(
            'pharmacie_status',
            sprintf('Stock ajusté : %+d unité(s) sur le lot %s.', $movement->quantity, $batch->number),
        );
    }

    /**
     * Bloquer ou débloquer un lot (suspicion, rappel).
     */
    public function toggleBatch(Request $request, Batch $batch, Auditor $auditor): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $actor = $this->user($request);

        $blocked = $batch->status === Batch::STATUS_BLOCKED;

        $batch->update([
            'status' => $blocked ? Batch::STATUS_ACTIVE : Batch::STATUS_BLOCKED,
            'block_reason' => $blocked ? null : Text::clean($data['reason'] ?? null),
        ]);

        $auditor->record(
            $blocked ? 'batch_unblocked' : 'batch_blocked',
            $batch,
            sprintf('Lot %s %s', $batch->number, $blocked ? 'débloqué' : 'bloqué'),
            ['status' => $blocked ? Batch::STATUS_BLOCKED : Batch::STATUS_ACTIVE],
            ['status' => $batch->status, 'reason' => $batch->block_reason],
            $actor,
        );

        return redirect()->route('pharmacie.stock.batches.show', $batch)->with(
            'pharmacie_status',
            $blocked
                ? sprintf('Le lot %s est de nouveau délivrable.', $batch->number)
                : sprintf('Le lot %s est bloqué : il ne peut plus être délivré.', $batch->number),
        );
    }

    private function warningDays(): int
    {
        return max(0, (int) config('pharmacie.stock.expiry_warning_days', 90));
    }

    /**
     * Les lots qui périment bientôt, pour l'écran des péremptions.
     */
    public function expiring(Request $request): View
    {
        $days = ctype_digit((string) $request->query('jours')) ? (int) $request->query('jours') : $this->warningDays();
        $limit = Carbon::today()->addDays($days);

        $stocks = Stock::query()->ofFacility()
            ->inStock()
            ->whereHas('batch', fn ($query) => $query->whereNotNull('expires_on')->whereDate('expires_on', '<=', $limit))
            ->with(['batch.product', 'location'])
            ->get()
            ->sortBy(fn (Stock $stock): string => (string) $stock->batch?->expires_on);

        return view('pharmacie::stock.expiring', [
            'stocks' => $stocks,
            'days' => $days,
            'expired' => $stocks->filter(fn (Stock $stock): bool => (bool) $stock->batch?->isExpired()),
            'value' => (int) $stocks->sum(fn (Stock $stock): int => (int) $stock->quantity * (int) ($stock->batch?->purchase_price ?? 0)),
        ]);
    }
}
