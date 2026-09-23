<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Keneya\Pharmacie\Actions\ManagePurchaseOrder;
use Keneya\Pharmacie\Actions\RecordReception;
use Keneya\Pharmacie\Audit\Auditor;
use Keneya\Pharmacie\Models\Location;
use Keneya\Pharmacie\Models\Product;
use Keneya\Pharmacie\Models\PurchaseOrder;
use Keneya\Pharmacie\Models\Reception;
use Keneya\Pharmacie\Models\Stock;
use Keneya\Pharmacie\Models\Supplier;
use Keneya\Pharmacie\Pharmacie;
use Keneya\Pharmacie\Support\Facility;
use Keneya\Pharmacie\Support\Text;

/**
 * L'approvisionnement : fournisseurs, commandes, réceptions.
 *
 * Trois écrans, un seul cycle : on commande, on reçoit, le stock entre. La
 * réception est le seul chemin par lequel un lot naît.
 */
final class SupplyController extends PharmacieController
{
    // ------------------------------------------------------ Fournisseurs

    public function suppliers(): View
    {
        return view('pharmacie::supply.suppliers', [
            'suppliers' => Supplier::query()->ofFacility()
                ->withCount(['orders', 'receptions'])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function supplier(Supplier $supplier): View
    {
        return view('pharmacie::supply.supplier', [
            'supplier' => $supplier,
            'orders' => $supplier->orders()->latest('id')->limit(50)->get(),
            'receptions' => $supplier->receptions()->with('lines')->latest('id')->limit(50)->get(),
        ]);
    }

    public function storeSupplier(Request $request, Auditor $auditor): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', Rule::unique('pharmacie_suppliers', 'code')->where('facility_id', Facility::current())],
            'name' => ['required', 'string', 'max:191'],
            'contact_name' => ['nullable', 'string', 'max:191'],
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:191'],
            'address' => ['nullable', 'string', 'max:191'],
            'payment_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $supplier = Supplier::create([
            'facility_id' => Facility::current(),
            'code' => strtoupper((string) Text::clean($data['code'])),
            'name' => (string) Text::clean($data['name']),
            'contact_name' => Text::clean($data['contact_name'] ?? null),
            'phone' => Text::clean($data['phone'] ?? null),
            'email' => Text::clean($data['email'] ?? null),
            'address' => Text::clean($data['address'] ?? null),
            'payment_days' => isset($data['payment_days']) ? (int) $data['payment_days'] : null,
            'lead_time_days' => isset($data['lead_time_days']) ? (int) $data['lead_time_days'] : null,
            'notes' => Text::clean($data['notes'] ?? null),
            'is_active' => true,
        ]);

        $auditor->record(
            'supplier_created',
            $supplier,
            sprintf('Fournisseur « %s » (%s) créé', $supplier->name, $supplier->code),
            [],
            ['code' => $supplier->code, 'name' => $supplier->name],
            $this->user($request),
        );

        return redirect()->route('pharmacie.supply.suppliers.show', $supplier)
            ->with('pharmacie_status', sprintf('Le fournisseur « %s » a été créé.', $supplier->name));
    }

    public function toggleSupplier(Request $request, Supplier $supplier, Auditor $auditor): RedirectResponse
    {
        $active = ! $supplier->is_active;
        $supplier->update(['is_active' => $active]);

        $auditor->record(
            $active ? 'supplier_activated' : 'supplier_deactivated',
            $supplier,
            sprintf('Fournisseur « %s » %s', $supplier->name, $active ? 'activé' : 'désactivé'),
            ['is_active' => ! $active],
            ['is_active' => $active],
            $this->user($request),
        );

        return redirect()->route('pharmacie.supply.suppliers.index')->with(
            'pharmacie_status',
            sprintf('Le fournisseur « %s » est %s.', $supplier->name, $active ? 'actif' : 'désactivé'),
        );
    }

    // --------------------------------------------------------- Commandes

    public function orders(Request $request): View
    {
        $status = array_key_exists((string) $request->query('statut'), PurchaseOrder::statusLabels())
            ? (string) $request->query('statut')
            : null;

        return view('pharmacie::supply.orders', [
            'orders' => PurchaseOrder::query()->ofFacility()
                ->when($status, fn ($query) => $query->where('status', $status))
                ->with(['supplier', 'items'])
                ->latest('id')
                ->paginate(30)
                ->withQueryString(),
            'statuses' => PurchaseOrder::statusLabels(),
            'status' => $status,
            'suppliers' => Supplier::query()->ofFacility()->active()->get(),
            'products' => Product::query()->ofFacility()->active()->get(),
            // Ce qui manque : le point de départ naturel d'une commande.
            'suggestions' => $this->reorderSuggestions(),
        ]);
    }

    public function order(PurchaseOrder $order): View
    {
        return view('pharmacie::supply.order', [
            'order' => $order->load(['supplier', 'items.product', 'receptions']),
            'locations' => Location::query()->ofFacility()->active()->get(),
        ]);
    }

    public function storeOrder(Request $request, ManagePurchaseOrder $action): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:pharmacie_suppliers,id'],
            'expected_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:pharmacie_products,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.unit_price' => ['nullable', 'integer', 'min:0'],
        ]);

        $order = $action->create(
            Supplier::query()->findOrFail((int) $data['supplier_id']),
            array_values(array_map(static fn (array $line): array => [
                'product_id' => (int) $line['product_id'],
                'quantity' => (int) $line['quantity'],
                'unit_price' => (int) ($line['unit_price'] ?? 0),
            ], $data['lines'])),
            $this->user($request),
            ['expected_on' => $data['expected_on'] ?? null, 'notes' => $data['notes'] ?? null],
        );

        return redirect()->route('pharmacie.supply.orders.show', $order)
            ->with('pharmacie_status', sprintf('Commande %s créée. Envoyez-la au fournisseur quand elle est prête.', $order->number));
    }

    public function sendOrder(Request $request, PurchaseOrder $order, ManagePurchaseOrder $action): RedirectResponse
    {
        $sent = $action->send($order, $this->user($request));

        return redirect()->route('pharmacie.supply.orders.show', $sent)
            ->with('pharmacie_status', sprintf('Commande %s envoyée.', $sent->number));
    }

    public function cancelOrder(Request $request, PurchaseOrder $order, ManagePurchaseOrder $action): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $cancelled = $action->cancel($order, (string) $data['reason'], $this->user($request));

        return redirect()->route('pharmacie.supply.orders.show', $cancelled)
            ->with('pharmacie_status', sprintf('Commande %s annulée.', $cancelled->number));
    }

    // -------------------------------------------------------- Réceptions

    public function receptions(Request $request): View
    {
        return view('pharmacie::supply.receptions', [
            'receptions' => Reception::query()->ofFacility()
                ->with(['supplier', 'location', 'lines'])
                ->latest('id')
                ->paginate(30),
            'suppliers' => Supplier::query()->ofFacility()->active()->get(),
            'locations' => Location::query()->ofFacility()->active()->get(),
            'products' => Product::query()->ofFacility()->active()->get(),
            'openOrders' => PurchaseOrder::query()->ofFacility()
                ->whereIn('status', [PurchaseOrder::STATUS_SENT, PurchaseOrder::STATUS_PARTIAL])
                ->with('supplier')
                ->get(),
        ]);
    }

    public function reception(Reception $reception): View
    {
        return view('pharmacie::supply.reception', [
            'reception' => $reception->load(['supplier', 'location', 'order', 'lines.product', 'lines.batch']),
            'facility' => Pharmacie::facility(),
        ]);
    }

    public function storeReception(Request $request, RecordReception $action): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:pharmacie_suppliers,id'],
            'location_id' => ['required', 'integer', 'exists:pharmacie_locations,id'],
            'purchase_order_id' => ['nullable', 'integer', 'exists:pharmacie_purchase_orders,id'],
            'received_on' => ['nullable', 'date'],
            'delivery_note' => ['nullable', 'string', 'max:64'],
            'anomalies' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:pharmacie_products,id'],
            'lines.*.batch_number' => ['required', 'string', 'max:64'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.expires_on' => ['nullable', 'date'],
            'lines.*.manufactured_on' => ['nullable', 'date'],
            'lines.*.unit_price' => ['nullable', 'integer', 'min:0'],
        ]);

        $reception = $action->handle(
            Supplier::query()->findOrFail((int) $data['supplier_id']),
            Location::query()->findOrFail((int) $data['location_id']),
            array_values(array_map(static fn (array $line): array => [
                'product_id' => (int) $line['product_id'],
                'batch_number' => (string) $line['batch_number'],
                'quantity' => (int) $line['quantity'],
                'expires_on' => $line['expires_on'] ?? null,
                'manufactured_on' => $line['manufactured_on'] ?? null,
                'unit_price' => (int) ($line['unit_price'] ?? 0),
            ], $data['lines'])),
            $this->user($request),
            [
                'purchase_order_id' => isset($data['purchase_order_id']) ? (int) $data['purchase_order_id'] : null,
                'received_on' => $data['received_on'] ?? null,
                'delivery_note' => $data['delivery_note'] ?? null,
                'anomalies' => $data['anomalies'] ?? null,
                'notes' => $data['notes'] ?? null,
            ],
        );

        return redirect()->route('pharmacie.supply.receptions.show', $reception)
            ->with('pharmacie_status', sprintf('Réception %s enregistrée : le stock est à jour.', $reception->number));
    }

    /**
     * Ce qui est sous le seuil : de quoi préparer une commande sans chercher.
     *
     * @return list<array{product: Product, available: int, suggested: int}>
     */
    private function reorderSuggestions(): array
    {
        $rows = [];

        foreach (Product::query()->ofFacility()->where('is_active', true)->get() as $product) {
            $available = (int) Stock::query()
                ->where('product_id', $product->id)
                ->sum('quantity');

            if ($available > (int) $product->min_threshold) {
                continue;
            }

            $target = (int) ($product->max_threshold ?? max(1, (int) $product->min_threshold * 2));

            $rows[] = [
                'product' => $product,
                'available' => $available,
                'suggested' => max(1, $target - $available),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $a['available'] <=> $b['available']);

        return array_slice($rows, 0, 20);
    }
}
