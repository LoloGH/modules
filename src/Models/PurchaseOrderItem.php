<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une ligne de commande : ce qui est demandé, et ce qui en est déjà arrivé.
 *
 * Le libellé est recopié au moment de la commande : si le catalogue change
 * plus tard, la commande passée garde ce qui avait été commandé.
 */
class PurchaseOrderItem extends Model
{
    protected $table = 'pharmacie_purchase_order_items';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'received_quantity' => 'integer',
            'unit_price' => 'integer',
            'amount' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** Ce qui reste à recevoir sur cette ligne. */
    public function outstanding(): int
    {
        return max(0, (int) $this->quantity - (int) $this->received_quantity);
    }
}
