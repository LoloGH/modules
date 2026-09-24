<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une ligne d'inventaire : un lot, ce qu'on croyait avoir, ce qu'on a compte.
 *
 * `gap` est signe : positif quand on a trouve plus que prevu, negatif quand
 * il manque. Un ecart se justifie ; il ne s'efface pas.
 */
class InventoryLine extends Model
{
    protected $table = 'pharmacie_inventory_lines';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expected_quantity' => 'integer',
            'counted_quantity' => 'integer',
            'gap' => 'integer',
        ];
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class, 'inventory_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_id');
    }

    public function isCounted(): bool
    {
        return $this->counted_quantity !== null;
    }
}
