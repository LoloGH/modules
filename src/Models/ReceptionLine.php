<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une ligne de réception : un produit, un lot, une quantité, un prix.
 *
 * C'est ici que le lot naît : sans réception, aucun lot ne devrait exister.
 */
class ReceptionLine extends Model
{
    protected $table = 'pharmacie_reception_lines';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price' => 'integer', 'amount' => 'integer'];
    }

    public function reception(): BelongsTo
    {
        return $this->belongsTo(Reception::class, 'reception_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_id');
    }
}
