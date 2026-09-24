<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une ligne de transfert : ce qui est demandé, ce qui est parti, ce qui est
 * arrivé.
 *
 * L'écart entre parti et arrivé n'est pas effacé : il se justifie, et il
 * reste lisible. C'est ce qui permet de savoir où le stock se perd.
 */
class TransferLine extends Model
{
    protected $table = 'pharmacie_transfer_lines';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'received_quantity' => 'integer'];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class, 'transfer_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_id');
    }

    /** L'écart de transport : parti moins arrivé. */
    public function gap(): int
    {
        return max(0, (int) $this->quantity - (int) $this->received_quantity);
    }
}
