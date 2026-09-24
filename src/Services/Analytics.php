<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Keneya\Pharmacie\Models\Batch;
use Keneya\Pharmacie\Models\Dispensation;
use Keneya\Pharmacie\Models\Loss;
use Keneya\Pharmacie\Models\Product;
use Keneya\Pharmacie\Models\PurchaseOrder;
use Keneya\Pharmacie\Models\PurchaseOrderItem;
use Keneya\Pharmacie\Models\Stock;
use Keneya\Pharmacie\Models\StockMovement;

/**
 * Ce que le stock raconte : consommation, valeur, pertes, ruptures, et le
 * besoin à venir.
 *
 * Tout est recalculé à la lecture, à partir du grand livre des mouvements et
 * des pièces. Aucune table d'agrégats : un chiffre stocké se désynchronise
 * un jour ou l'autre, et un tableau de bord qui ment est pire qu'un tableau
 * de bord vide.
 *
 * Deux honnêtetés à garder en tête, dites aussi dans les écrans :
 *
 *   - la **consommation** est ce qui est sorti pour être délivré, diminué de
 *     ce que les annulations ont fait revenir — pas ce qui a été prescrit ;
 *   - la **rotation** rapporte la consommation de la période à la valeur du
 *     stock d'aujourd'hui : le module ne photographie pas la valeur du stock
 *     chaque nuit, donc c'est un ordre de grandeur, pas une comptabilité.
 */
final class Analytics
{
    /**
     * Les chiffres de la période. `$filters` accepte `category_id` et
     * `location_id`.
     *
     * @param  array{category_id?: ?int, location_id?: ?int}  $filters
     * @return array<string, mixed>
     */
    public function summary(Carbon $from, Carbon $to, array $filters = []): array
    {
        $days = max(1, (int) $from->copy()->startOfDay()->diffInDays($to->copy()->endOfDay(), false) + 1);

        $consumed = $this->consumedUnits($from, $to, $filters);
        $consumedValue = $this->consumedValue($from, $to, $filters);
        $stockValue = $this->stockValue($filters);
        $receivedValue = $this->receivedValue($from, $to, $filters);

        $losses = $this->lossesByKind($from, $to);
        $lossValue = array_sum(array_column($losses, 'value'));
        $expiredValue = $losses[Loss::KIND_EXPIRED]['value'] ?? 0;

        $products = $this->activeProducts($filters);
        $onHand = $this->onHandByProduct($filters);

        $outOfStock = $products->filter(fn (Product $p): bool => (int) ($onHand[$p->id] ?? 0) <= 0);
        $belowThreshold = $products->filter(
            fn (Product $p): bool => (int) ($onHand[$p->id] ?? 0) > 0
                && (int) ($onHand[$p->id] ?? 0) <= (int) $p->min_threshold,
        );

        $dispensations = Dispensation::query()->ofFacility()
            ->where('status', Dispensation::STATUS_DISPENSED)
            ->whereBetween('dispensed_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->get();

        return [
            'days' => $days,
            'consumed_units' => $consumed,
            'consumed_value' => $consumedValue,
            'daily_average' => $days > 0 ? $consumed / $days : 0.0,
            'stock_value' => $stockValue,
            'received_value' => $receivedValue,

            // Rotation annualisée : combien de fois le stock actuel serait
            // renouvelé en un an au rythme de la période observée.
            'turnover' => $stockValue > 0 ? ($consumedValue * (365 / $days)) / $stockValue : null,

            'loss_value' => $lossValue,
            'losses' => $losses,
            // Part de ce qui est entré qui a fini périmé : la mesure la plus
            // parlante du gaspillage, quand des entrées existent.
            'expiry_rate' => $receivedValue > 0 ? $expiredValue / $receivedValue : null,

            'products' => $products->count(),
            'out_of_stock' => $outOfStock->count(),
            'below_threshold' => $belowThreshold->count(),
            'shortage_rate' => $products->count() > 0 ? $outOfStock->count() / $products->count() : null,

            'dispensations' => $dispensations->count(),
            'dispensations_value' => (int) $dispensations->sum('total'),
            'shortfalls' => $dispensations->filter(fn (Dispensation $d): bool => (int) $d->outstanding > 0)->count(),
        ];
    }

    /**
     * La consommation produit par produit sur la période, la plus forte en
     * premier.
     *
     * @param  array{category_id?: ?int, location_id?: ?int}  $filters
     * @return Collection<int, array{product: Product, quantity: int, value: int}>
     */
    public function consumptionByProduct(Carbon $from, Carbon $to, array $filters = [], int $limit = 0): Collection
    {
        $movements = $this->consumptionMovements($from, $to, $filters)
            ->with('batch')
            ->get()
            ->groupBy('product_id');

        $products = $this->activeProducts($filters, false)->keyBy('id');

        $rows = collect();

        foreach ($movements as $productId => $group) {
            $product = $products->get((int) $productId);

            if ($product === null) {
                continue;
            }

            $quantity = $this->netUnits($group);

            if ($quantity <= 0) {
                continue;
            }

            $rows->push([
                'product' => $product,
                'quantity' => $quantity,
                'value' => $this->netValue($group),
            ]);
        }

        $rows = $rows->sortByDesc('quantity')->values();

        return $limit > 0 ? $rows->take($limit)->values() : $rows;
    }

    /**
     * La consommation par catégorie : de quoi voir où passe l'argent.
     *
     * @param  array{category_id?: ?int, location_id?: ?int}  $filters
     * @return Collection<int, array{label: string, quantity: int, value: int}>
     */
    public function consumptionByCategory(Carbon $from, Carbon $to, array $filters = []): Collection
    {
        $rows = [];

        foreach ($this->consumptionByProduct($from, $to, $filters) as $row) {
            $label = $row['product']->category?->name ?? 'Sans catégorie';

            $rows[$label] ??= ['label' => $label, 'quantity' => 0, 'value' => 0];
            $rows[$label]['quantity'] += $row['quantity'];
            $rows[$label]['value'] += $row['value'];
        }

        return collect(array_values($rows))->sortByDesc('value')->values();
    }

    /**
     * Le besoin à venir, produit par produit.
     *
     * Le calcul se dit en une phrase : au rythme observé, il faut de quoi
     * tenir l'horizon, plus le délai de livraison, plus une marge de
     * sécurité ; on retranche ce qu'on a et ce qui est déjà commandé.
     *
     * Un produit sans consommation observée n'est pas prévu : on ne commande
     * pas sur une moyenne qui n'existe pas. Son seuil, lui, reste surveillé
     * par les alertes.
     *
     * @param  array{category_id?: ?int, location_id?: ?int}  $filters
     * @return Collection<int, array{product: Product, daily: float, on_hand: int, on_order: int, coverage: ?float, needed: int, risk: string}>
     */
    public function forecast(Carbon $from, Carbon $to, array $filters = []): Collection
    {
        $days = max(1, (int) $from->copy()->startOfDay()->diffInDays($to->copy()->endOfDay(), false) + 1);

        $horizon = max(1, (int) config('pharmacie.forecast.horizon_days', 60));
        $lead = max(0, (int) config('pharmacie.forecast.lead_time_days', 30));
        $safety = max(0, (int) config('pharmacie.forecast.safety_days', 15));

        $onHand = $this->onHandByProduct($filters);
        $onOrder = $this->onOrderByProduct();

        $rows = collect();

        foreach ($this->consumptionByProduct($from, $to, $filters) as $row) {
            $product = $row['product'];
            $daily = $row['quantity'] / $days;
            $stock = (int) ($onHand[$product->id] ?? 0);
            $ordered = (int) ($onOrder[$product->id] ?? 0);

            $coverage = $daily > 0 ? $stock / $daily : null;
            $needed = (int) max(0, ceil($daily * ($horizon + $lead + $safety)) - $stock - $ordered);

            $rows->push([
                'product' => $product,
                'daily' => $daily,
                'on_hand' => $stock,
                'on_order' => $ordered,
                'coverage' => $coverage,
                'needed' => $needed,
                'risk' => $this->risk($coverage, $lead, $safety),
            ]);
        }

        return $rows->sortBy(fn (array $row): float => $row['coverage'] ?? INF)->values();
    }

    /**
     * La valeur du stock, au prix d'achat des lots. Le prix de vente ne dit
     * pas ce que l'établissement a immobilisé.
     *
     * @param  array{category_id?: ?int, location_id?: ?int}  $filters
     */
    public function stockValue(array $filters = []): int
    {
        return (int) $this->stockQuery($filters)
            ->with('batch')
            ->get()
            ->sum(fn (Stock $stock): int => (int) $stock->quantity * (int) ($stock->batch?->purchase_price ?? 0));
    }

    /**
     * Les pertes de la période, par nature.
     *
     * @return array<string, array{label: string, quantity: int, value: int}>
     */
    public function lossesByKind(Carbon $from, Carbon $to): array
    {
        $rows = [];

        $losses = Loss::query()->ofFacility()
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->get();

        foreach (Loss::kindLabels() as $kind => $label) {
            $group = $losses->where('kind', $kind);

            if ($group->isEmpty()) {
                continue;
            }

            $rows[$kind] = [
                'label' => $label,
                'quantity' => (int) $group->sum('quantity'),
                'value' => (int) $group->sum('value'),
            ];
        }

        return $rows;
    }

    /**
     * Les chiffres du tableau de bord : peu, mais vrais.
     *
     * @return array<string, int>
     */
    public function dashboard(): array
    {
        $warning = max(0, (int) config('pharmacie.stock.expiry_warning_days', 90));
        $limit = Carbon::today()->addDays($warning);

        $expiring = Batch::query()->ofFacility()
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '>=', Carbon::today())
            ->whereDate('expires_on', '<=', $limit)
            ->get()
            ->filter(fn (Batch $batch): bool => $batch->onHand() > 0);

        $today = Dispensation::query()->ofFacility()
            ->where('status', Dispensation::STATUS_DISPENSED)
            ->whereDate('dispensed_at', Carbon::today())
            ->get();

        return [
            'products_in_stock' => (int) Stock::query()->ofFacility()->where('quantity', '>', 0)
                ->distinct()->count('product_id'),
            'stock_value' => $this->stockValue(),
            'expiring' => $expiring->count(),
            'dispensations_today' => $today->count(),
            'dispensed_value_today' => (int) $today->sum('total'),
        ];
    }

    // --------------------------------------------------------------- Détail

    /**
     * @param  array{category_id?: ?int, location_id?: ?int}  $filters
     */
    private function consumedUnits(Carbon $from, Carbon $to, array $filters): int
    {
        return $this->netUnits($this->consumptionMovements($from, $to, $filters)->get());
    }

    /**
     * @param  array{category_id?: ?int, location_id?: ?int}  $filters
     */
    private function consumedValue(Carbon $from, Carbon $to, array $filters): int
    {
        return $this->netValue($this->consumptionMovements($from, $to, $filters)->with('batch')->get());
    }

    /**
     * Les sorties pour dispensation et les retours d'annulation : c'est la
     * différence des deux qui a réellement été consommé.
     *
     * @param  array{category_id?: ?int, location_id?: ?int}  $filters
     * @return Builder<StockMovement>
     */
    private function consumptionMovements(Carbon $from, Carbon $to, array $filters)
    {
        return StockMovement::query()->ofFacility()
            ->whereIn('kind', [StockMovement::KIND_DISPENSING, StockMovement::KIND_CANCELLATION])
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($filters['location_id'] ?? null, fn ($query, $id) => $query->where('location_id', $id))
            ->when(
                $filters['category_id'] ?? null,
                fn ($query, $id) => $query->whereHas('product', fn ($p) => $p->where('category_id', $id)),
            );
    }

    /**
     * @param  Collection<int, StockMovement>  $movements
     */
    private function netUnits(Collection $movements): int
    {
        // Les sorties sont négatives, les retours positifs : la somme
        // signée, changée de signe, est ce qui est parti pour de bon.
        return (int) -$movements->sum(fn (StockMovement $movement): int => (int) $movement->quantity);
    }

    /**
     * @param  Collection<int, StockMovement>  $movements
     */
    private function netValue(Collection $movements): int
    {
        return (int) -$movements->sum(
            fn (StockMovement $movement): int => (int) $movement->quantity * (int) ($movement->batch?->purchase_price ?? 0),
        );
    }

    /**
     * @param  array{category_id?: ?int, location_id?: ?int}  $filters
     */
    private function receivedValue(Carbon $from, Carbon $to, array $filters): int
    {
        return (int) StockMovement::query()->ofFacility()
            ->where('kind', StockMovement::KIND_RECEPTION)
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($filters['location_id'] ?? null, fn ($query, $id) => $query->where('location_id', $id))
            ->when(
                $filters['category_id'] ?? null,
                fn ($query, $id) => $query->whereHas('product', fn ($p) => $p->where('category_id', $id)),
            )
            ->with('batch')
            ->get()
            ->sum(fn (StockMovement $movement): int => (int) $movement->quantity * (int) ($movement->batch?->purchase_price ?? 0));
    }

    /**
     * @param  array{category_id?: ?int, location_id?: ?int}  $filters
     * @return Collection<int, Product>
     */
    private function activeProducts(array $filters, bool $onlyActive = true): Collection
    {
        return Product::query()->ofFacility()
            ->when($onlyActive, fn ($query) => $query->where('is_active', true))
            ->when($filters['category_id'] ?? null, fn ($query, $id) => $query->where('category_id', $id))
            ->with('category')
            ->get();
    }

    /**
     * @param  array{category_id?: ?int, location_id?: ?int}  $filters
     * @return array<int, int>
     */
    private function onHandByProduct(array $filters): array
    {
        return $this->stockQuery($filters)
            ->selectRaw('product_id, SUM(quantity) as total')
            ->groupBy('product_id')
            ->pluck('total', 'product_id')
            ->map(static fn ($total): int => (int) $total)
            ->all();
    }

    /**
     * Ce qui est commandé et pas encore reçu : sans cela, on recommanderait
     * ce qui est déjà en route.
     *
     * @return array<int, int>
     */
    private function onOrderByProduct(): array
    {
        $rows = PurchaseOrderItem::query()
            ->whereHas(
                'order',
                fn ($query) => $query->ofFacility()
                    ->whereIn('status', [PurchaseOrder::STATUS_SENT, PurchaseOrder::STATUS_PARTIAL]),
            )
            ->get();

        $onOrder = [];

        foreach ($rows as $row) {
            $remaining = max(0, (int) $row->quantity - (int) $row->received_quantity);

            if ($remaining === 0) {
                continue;
            }

            $onOrder[(int) $row->product_id] = ($onOrder[(int) $row->product_id] ?? 0) + $remaining;
        }

        return $onOrder;
    }

    /**
     * @param  array{category_id?: ?int, location_id?: ?int}  $filters
     * @return Builder<Stock>
     */
    private function stockQuery(array $filters)
    {
        return Stock::query()->ofFacility()
            ->when($filters['location_id'] ?? null, fn ($query, $id) => $query->where('location_id', $id))
            ->when(
                $filters['category_id'] ?? null,
                fn ($query, $id) => $query->whereHas('product', fn ($p) => $p->where('category_id', $id)),
            );
    }

    /**
     * Le risque, dit en un mot : ce qui ne tiendra pas jusqu'à la prochaine
     * livraison est en rupture avant elle.
     */
    private function risk(?float $coverage, int $lead, int $safety): string
    {
        if ($coverage === null) {
            return 'inconnu';
        }

        return match (true) {
            $coverage <= $lead => 'rupture',
            $coverage <= $lead + $safety => 'tendu',
            default => 'suffisant',
        };
    }
}
