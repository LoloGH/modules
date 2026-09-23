<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Keneya\Pharmacie\Models\Batch;
use Keneya\Pharmacie\Models\Dispensation;
use Keneya\Pharmacie\Models\Product;
use Keneya\Pharmacie\Models\PurchaseOrder;
use Keneya\Pharmacie\Models\Stock;
use Keneya\Pharmacie\Pharmacie;
use Keneya\Pharmacie\Support\Alert;
use Keneya\Pharmacie\Support\Money;

/**
 * Les alertes de la pharmacie : ce qui attend un geste, pour celui qui les
 * lit.
 *
 * Aucune table de notifications, aucun envoi : chaque alerte est un état de la
 * base, relu à l'affichage. Un lot détruit, une commande reçue, un stock
 * refait, et l'alerte disparaît d'elle-même — elle ne peut donc pas mentir.
 *
 * Chacune n'est calculée que si la personne a le droit d'agir dessus : on ne
 * signale jamais un travail qu'on ne peut pas faire, et un lien d'alerte ne
 * mène jamais à un refus.
 */
final class AlertCenter
{
    /**
     * @return list<Alert>
     */
    public function for(?Authenticatable $user): array
    {
        if ($user === null) {
            return [];
        }

        $can = fn (string $ability): bool => Gate::forUser($user)->allows($ability);

        $alerts = array_merge(
            $can('pharmacie.stock.view') ? $this->expired() : [],
            $can('pharmacie.stock.view') ? $this->expiringSoon() : [],
            $can('pharmacie.stock.view') ? $this->shortages() : [],
            $can('pharmacie.stock.view') ? $this->blocked() : [],
            $can('pharmacie.stock.receive') ? $this->ordersToReceive() : [],
            $can('pharmacie.dispensing.view') ? $this->shortfalls() : [],
            $can('pharmacie.dispensing.create') ? $this->awaitingBilling() : [],
            $can('pharmacie.queue.view') ? $this->queue() : [],
        );

        usort($alerts, static fn (Alert $a, Alert $b): int => $a->weight() <=> $b->weight());

        return $alerts;
    }

    public function count(?Authenticatable $user): int
    {
        return count($this->for($user));
    }

    /**
     * Du périmé encore en stock : il ne peut plus être délivré, il doit
     * sortir.
     *
     * @return list<Alert>
     */
    private function expired(): array
    {
        $stocks = $this->stockWithBatches()
            ->filter(fn (Stock $stock): bool => (bool) $stock->batch?->isExpired());

        if ($stocks->isEmpty()) {
            return [];
        }

        return [new Alert(
            'stock_expired',
            Alert::LEVEL_DANGER,
            sprintf('%d lot(s) périmé(s) encore en stock', $stocks->count()),
            sprintf(
                '%d unité(s), soit %s au prix d\'achat : à sortir du stock et à détruire.',
                (int) $stocks->sum('quantity'),
                Money::format((int) $stocks->sum(fn (Stock $s): int => (int) $s->quantity * (int) ($s->batch?->purchase_price ?? 0))),
            ),
            route('pharmacie.stock.expiring'),
            'Voir les péremptions',
        )];
    }

    /**
     * @return list<Alert>
     */
    private function expiringSoon(): array
    {
        $days = max(0, (int) config('pharmacie.stock.expiry_warning_days', 90));
        $limit = Carbon::today()->addDays($days);

        $stocks = $this->stockWithBatches()->filter(
            fn (Stock $stock): bool => $stock->batch?->expires_on !== null
                && ! $stock->batch->isExpired()
                && $stock->batch->expires_on->lte($limit),
        );

        if ($stocks->isEmpty()) {
            return [];
        }

        return [new Alert(
            'stock_expiring',
            Alert::LEVEL_WARN,
            sprintf('%d lot(s) périment dans moins de %d jours', $stocks->count(), $days),
            sprintf('%d unité(s) concernées : à servir en priorité, ou à retourner.', (int) $stocks->sum('quantity')),
            route('pharmacie.stock.expiring'),
            'Voir les péremptions',
        )];
    }

    /**
     * Ruptures et seuils : ce qu'il faut commander.
     *
     * @return list<Alert>
     */
    private function shortages(): array
    {
        $available = Stock::query()->ofFacility()
            ->selectRaw('product_id, SUM(quantity) as total')
            ->groupBy('product_id')
            ->pluck('total', 'product_id');

        $out = [];
        $low = [];

        foreach (Product::query()->ofFacility()->where('is_active', true)->get() as $product) {
            $quantity = (int) ($available[$product->id] ?? 0);

            if ($quantity <= 0) {
                $out[] = $product;
            } elseif ($quantity <= (int) $product->min_threshold) {
                $low[] = $product;
            }
        }

        $alerts = [];

        if ($out !== []) {
            $alerts[] = new Alert(
                'stock_out',
                Alert::LEVEL_DANGER,
                sprintf('%d produit(s) en rupture', count($out)),
                'Rien à délivrer : '.implode(', ', array_map(static fn (Product $p): string => $p->name, array_slice($out, 0, 5)))
                    .(count($out) > 5 ? '…' : ''),
                route('pharmacie.stock.index', ['alerte' => 'rupture']),
                'Commander',
            );
        }

        if ($low !== []) {
            $alerts[] = new Alert(
                'stock_low',
                Alert::LEVEL_WARN,
                sprintf('%d produit(s) sous leur seuil', count($low)),
                'À commander avant la rupture.',
                route('pharmacie.stock.index', ['alerte' => 'seuil']),
                'Commander',
            );
        }

        return $alerts;
    }

    /**
     * @return list<Alert>
     */
    private function blocked(): array
    {
        $batches = Batch::query()->ofFacility()->where('status', Batch::STATUS_BLOCKED)->get();

        if ($batches->isEmpty()) {
            return [];
        }

        return [new Alert(
            'batches_blocked',
            Alert::LEVEL_WARN,
            sprintf('%d lot(s) bloqué(s)', $batches->count()),
            'Ils ne peuvent pas être délivrés tant que la décision n\'est pas levée.',
            route('pharmacie.stock.index'),
            'Voir le stock',
        )];
    }

    /**
     * @return list<Alert>
     */
    private function ordersToReceive(): array
    {
        $orders = PurchaseOrder::query()->ofFacility()
            ->whereIn('status', [PurchaseOrder::STATUS_SENT, PurchaseOrder::STATUS_PARTIAL])
            ->get();

        if ($orders->isEmpty()) {
            return [];
        }

        $late = $orders->filter(
            fn (PurchaseOrder $order): bool => $order->expected_on !== null && $order->expected_on->lt(today()),
        );

        return [new Alert(
            'orders_open',
            $late->isEmpty() ? Alert::LEVEL_INFO : Alert::LEVEL_WARN,
            sprintf('%d commande(s) en attente de livraison', $orders->count()),
            $late->isEmpty()
                ? 'Aucune n\'a dépassé sa date prévue.'
                : sprintf('%d au-delà de la date annoncée par le fournisseur.', $late->count()),
            route('pharmacie.supply.orders.index'),
            'Suivre',
        )];
    }

    /**
     * Les reliquats : ce qu'on a promis de servir et qui manque encore.
     *
     * @return list<Alert>
     */
    private function shortfalls(): array
    {
        $dispensations = Dispensation::query()->ofFacility()->dispensed()->where('outstanding', '>', 0)->get();

        if ($dispensations->isEmpty()) {
            return [];
        }

        return [new Alert(
            'dispensing_shortfall',
            Alert::LEVEL_WARN,
            sprintf('%d dispensation(s) avec un reliquat', $dispensations->count()),
            sprintf('%d unité(s) restent à délivrer aux patients.', (int) $dispensations->sum('outstanding')),
            route('pharmacie.dispensing.index', ['statut' => 'partielles']),
            'Voir',
        )];
    }

    /**
     * @return list<Alert>
     */
    private function awaitingBilling(): array
    {
        $dispensations = Dispensation::query()->ofFacility()
            ->dispensed()
            ->where('payment_status', Dispensation::PAYMENT_DUE)
            ->where('total', '>', 0)
            ->get();

        if ($dispensations->isEmpty()) {
            return [];
        }

        return [new Alert(
            'billing_due',
            Alert::LEVEL_INFO,
            sprintf('%d dispensation(s) à envoyer à la caisse', $dispensations->count()),
            sprintf('%s délivrés et pas encore transmis.', Money::format((int) $dispensations->sum('total'))),
            route('pharmacie.dispensing.index'),
            'Voir',
        )];
    }

    /**
     * @return list<Alert>
     */
    private function queue(): array
    {
        $waiting = array_sum(array_map(
            static fn ($queue): int => $queue->waiting,
            Pharmacie::queue()->queues(),
        ));

        if ($waiting <= 0) {
            return [];
        }

        return [new Alert(
            'queue_waiting',
            Alert::LEVEL_INFO,
            sprintf('%d patient(s) attendent à la pharmacie', $waiting),
            'Appelez le suivant depuis la file.',
            route('pharmacie.queue.index'),
            'Ouvrir la file',
        )];
    }

    /**
     * @return Collection<int, Stock>
     */
    private function stockWithBatches()
    {
        return Stock::query()->ofFacility()->inStock()->with('batch')->get();
    }
}
