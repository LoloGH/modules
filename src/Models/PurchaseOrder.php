<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Keneya\Pharmacie\Support\Facility;

/**
 * Une commande fournisseur, et son suivi : ce qui est demandé, ce qui est
 * arrivé, ce qui manque encore.
 *
 * Le statut ne s'écrit pas à la main : il se déduit de ce qui a été reçu
 * ({@see refreshStatus()}). Une commande n'est jamais « reçue » parce que
 * quelqu'un l'a cochée, mais parce que les réceptions le disent.
 */
class PurchaseOrder extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENT = 'sent';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'pharmacie_purchase_orders';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'ordered_on' => 'date',
            'expected_on' => 'date',
            'total' => 'integer',
            'sent_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_DRAFT => 'Brouillon',
            self::STATUS_SENT => 'Envoyée',
            self::STATUS_PARTIAL => 'Reçue en partie',
            self::STATUS_RECEIVED => 'Reçue',
            self::STATUS_CANCELLED => 'Annulée',
        ];
    }

    public function statusLabel(): string
    {
        return self::statusLabels()[$this->status] ?? (string) $this->status;
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            self::STATUS_RECEIVED => 'ok',
            self::STATUS_PARTIAL => 'info',
            self::STATUS_SENT => 'warn',
            self::STATUS_CANCELLED => 'danger',
            default => 'off',
        };
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class, 'purchase_order_id');
    }

    public function receptions(): HasMany
    {
        return $this->hasMany(Reception::class, 'purchase_order_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_SENT, self::STATUS_PARTIAL], true);
    }

    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /**
     * Ce qui reste à recevoir, ligne par ligne.
     */
    public function outstanding(): int
    {
        return (int) $this->items->sum(fn (PurchaseOrderItem $item): int => max(0, (int) $item->quantity - (int) $item->received_quantity));
    }

    /**
     * Relit ses lignes et en déduit son statut. À appeler après chaque
     * réception, dans la même transaction.
     */
    public function refreshStatus(): void
    {
        if (in_array($this->status, [self::STATUS_DRAFT, self::STATUS_CANCELLED], true)) {
            return;
        }

        $received = (int) $this->items()->sum('received_quantity');
        $expected = (int) $this->items()->sum('quantity');

        $this->update([
            'status' => match (true) {
                $received <= 0 => self::STATUS_SENT,
                $received >= $expected => self::STATUS_RECEIVED,
                default => self::STATUS_PARTIAL,
            },
        ]);
    }

    /**
     * @param  Builder<PurchaseOrder>  $query
     * @return Builder<PurchaseOrder>
     */
    public function scopeOfFacility(Builder $query, ?int $facilityId = null): Builder
    {
        return $query->where('facility_id', $facilityId ?? Facility::current());
    }
}
