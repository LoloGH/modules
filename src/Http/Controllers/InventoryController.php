<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Keneya\Pharmacie\Actions\CountInventory;
use Keneya\Pharmacie\Actions\RecordLoss;
use Keneya\Pharmacie\Models\Batch;
use Keneya\Pharmacie\Models\Category;
use Keneya\Pharmacie\Models\Inventory;
use Keneya\Pharmacie\Models\Location;
use Keneya\Pharmacie\Models\Loss;
use Keneya\Pharmacie\Models\Product;
use Keneya\Pharmacie\Models\Stock;

/**
 * Inventaires, pertes et destructions : regarder l'écart en face, et sortir
 * ce qui ne sera pas délivré.
 */
final class InventoryController extends PharmacieController
{
    public function index(): View
    {
        return view('pharmacie::inventory.index', [
            'inventories' => Inventory::query()->ofFacility()->with(['location', 'lines'])->latest('id')->paginate(20),
            'locations' => Location::query()->ofFacility()->active()->get(),
            'categories' => Category::query()->ofFacility()->active()->get(),
            'products' => Product::query()->ofFacility()->active()->get(),
            'scopes' => Inventory::scopeLabels(),
        ]);
    }

    public function show(Inventory $inventory): View
    {
        return view('pharmacie::inventory.show', [
            'inventory' => $inventory->load(['location', 'lines.batch', 'lines.product']),
        ]);
    }

    public function open(Request $request, CountInventory $action): RedirectResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'integer', 'exists:pharmacie_locations,id'],
            'scope' => ['required', Rule::in(array_keys(Inventory::scopeLabels()))],
            'category_id' => ['nullable', 'integer', 'exists:pharmacie_categories,id'],
            'product_id' => ['nullable', 'integer', 'exists:pharmacie_products,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $inventory = $action->open(
            Location::query()->findOrFail((int) $data['location_id']),
            (string) $data['scope'],
            $this->user($request),
            [
                'category_id' => isset($data['category_id']) ? (int) $data['category_id'] : null,
                'product_id' => isset($data['product_id']) ? (int) $data['product_id'] : null,
                'notes' => $data['notes'] ?? null,
            ],
        );

        return redirect()->route('pharmacie.inventory.show', $inventory)->with(
            'pharmacie_status',
            sprintf('Inventaire %s ouvert : %d ligne(s) à compter.', $inventory->number, $inventory->lines()->count()),
        );
    }

    public function count(Request $request, Inventory $inventory, CountInventory $action): RedirectResponse
    {
        $data = $request->validate([
            'lines' => ['required', 'array'],
            'lines.*.counted' => ['nullable', 'integer', 'min:0'],
            'lines.*.reason' => ['nullable', 'string', 'max:500'],
        ]);

        $counts = [];

        foreach ($data['lines'] as $lineId => $row) {
            $counts[(int) $lineId] = [
                'counted' => $row['counted'] ?? null,
                'reason' => $row['reason'] ?? null,
            ];
        }

        $action->count($inventory, $counts, $this->user($request));

        return redirect()->route('pharmacie.inventory.show', $inventory)
            ->with('pharmacie_status', 'Comptage enregistré. Rien n\'est corrigé tant qu\'il n\'est pas validé.');
    }

    public function validateInventory(Request $request, Inventory $inventory, CountInventory $action): RedirectResponse
    {
        $validated = $action->validate($inventory, $this->user($request));

        return redirect()->route('pharmacie.inventory.show', $validated)->with(
            'pharmacie_status',
            sprintf('Inventaire %s validé : les écarts sont passés en ajustements.', $validated->number),
        );
    }

    public function cancel(Request $request, Inventory $inventory, CountInventory $action): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $cancelled = $action->cancel($inventory, (string) $data['reason'], $this->user($request));

        return redirect()->route('pharmacie.inventory.index')
            ->with('pharmacie_status', sprintf('Inventaire %s abandonné.', $cancelled->number));
    }

    // --------------------------------------------- Pertes et destructions

    public function losses(Request $request): View
    {
        $stocks = Stock::query()->ofFacility()->inStock()->with(['batch.product', 'location'])->get();

        return view('pharmacie::inventory.losses', [
            'losses' => Loss::query()->ofFacility()->with(['batch.product', 'location'])->latest('id')->paginate(30),
            'stocks' => $stocks,
            'kinds' => Loss::kindLabels(),
            'total' => (int) Loss::query()->ofFacility()->sum('value'),
            // Ce qui est périmé et encore là : le premier candidat à la
            // destruction.
            'expired' => $stocks->filter(fn (Stock $stock): bool => (bool) $stock->batch?->isExpired()),
        ]);
    }

    public function storeLoss(Request $request, RecordLoss $action): RedirectResponse
    {
        $data = $request->validate([
            'batch_id' => ['required', 'integer', 'exists:pharmacie_batches,id'],
            'location_id' => ['required', 'integer', 'exists:pharmacie_locations,id'],
            'kind' => ['required', Rule::in(array_keys(Loss::kindLabels()))],
            'quantity' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:500'],
            'witness' => ['nullable', 'string', 'max:191'],
            'destroyed' => ['nullable', 'boolean'],
        ]);

        $loss = $action->handle(
            Batch::query()->findOrFail((int) $data['batch_id']),
            Location::query()->findOrFail((int) $data['location_id']),
            (int) $data['quantity'],
            (string) $data['kind'],
            (string) $data['reason'],
            $this->user($request),
            ['witness' => $data['witness'] ?? null, 'destroyed' => (bool) ($data['destroyed'] ?? false)],
        );

        return redirect()->route('pharmacie.inventory.losses')->with(
            'pharmacie_status',
            sprintf(
                '%s %s enregistrée : %d unité(s) sorties du stock.',
                $loss->destroyed ? 'Destruction' : 'Perte',
                $loss->number,
                $loss->quantity,
            ),
        );
    }
}
